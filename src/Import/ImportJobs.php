<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Private staging, transactional checkpoints, and bounded diagnostic history. */
final class ImportJobs {
	public const FIELDS = array( 'provider', 'name', 'code', 'province', 'city', 'address', 'phone', 'mobile_phone', 'landline_phone', 'source', 'metadata', 'postal_code', 'latitude', 'longitude', 'status', 'coordinates' );
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'tapin_imports'; }
	private static function error( string $message, int $status = 400 ): \WP_Error { \Tapin\ServicePointLocator\Service\OperationalLog::record( 'import_failed' ); return new \WP_Error( 'import', $message, array( 'status' => $status ) ); }
	private static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['data'] = json_decode( $row['data'], true );
		return $row;
	}
	private static function save( array $job ): void {
		global $wpdb;
		$result = $wpdb->update( self::table(), array( 'status' => $job['status'], 'data' => wp_json_encode( $job['data'] ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job['id'] ), array( '%s', '%s', '%s' ), array( '%d' ) );
		if ( false === $result ) { throw new \RuntimeException( 'ذخیره پیشرفت عملیات ناموفق بود.' ); }
	}
	public static function get_public( int $id ) {
		$row = self::get( $id );
		if ( ! $row ) { return self::error( 'عملیات پیدا نشد.', 404 ); }
		unset( $row['data']['path'], $row['data']['offset'] );
		if ( 'preview' === $row['status'] && ! empty( $row['data']['provider_values'] ) ) {
			$providers = ( new ProviderRepository() )->get_all( false );
			foreach ( $row['data']['provider_values'] as &$value ) { $value['provider_id'] = ProviderResolver::resolve( $value['value'], $providers ); }
			unset( $value );
		}
		return $row;
	}
	public static function recent(): array {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . self::table() . ' ORDER BY id DESC LIMIT 50' );
		return array_map( static fn( $id ) => self::get_public( (int) $id ), $ids ?: array() );
	}
	public static function upload( \WP_REST_Request $request ) {
		self::cleanup();
		$files = $request->get_file_params();
		$file = $files['file'] ?? null;
		if ( ! $file || ! is_scalar( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) { return self::error( 'بارگذاری فایل ناموفق است؛ محدودیت حجم سرور را بررسی کنید.' ); }
		$name = sanitize_file_name( $file['name'] );
			$type = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'xlsx' !== $type ) { return self::error( 'فقط فایل‌های Excel با پسوند XLSX پشتیبانی می‌شوند.' ); }
			return self::stage( $file['tmp_name'], $name );
	}
	/** Also available to trusted CLI tests; paths are never accepted through REST. */
	public static function stage( string $path, string $name ) {
		$type = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $type, array( 'csv', 'xlsx' ), true ) ) { return self::error( 'فقط CSV (UTF-8) و XLSX پشتیبانی می‌شوند. فایل XLS را ابتدا به XLSX تبدیل کنید.' ); }
		$limit = ( 'csv' === $type ? 50 : 10 ) * 1024 * 1024;
		if ( ! is_readable( $path ) || filesize( $path ) > $limit ) { return self::error( 'حداکثر حجم CSV برابر ۵۰ و XLSX برابر ۱۰ مگابایت است.' ); }
		$temp_dir = str_replace( '\\', '/', strtolower( (string) realpath( sys_get_temp_dir() ) ) );
		$web_root = rtrim( str_replace( '\\', '/', strtolower( (string) realpath( ABSPATH ) ) ), '/' ) . '/';
		if ( ! $temp_dir || 0 === strpos( $temp_dir . '/', $web_root ) ) {
			return self::error( 'پوشه موقت PHP باید قابل نوشتن و بیرون از پوشه عمومی وردپرس باشد.', 500 );
		}
		$temp = tempnam( sys_get_temp_dir(), 'tapin-import-' );
		if ( ! $temp ) { return self::error( 'پوشه موقت قابل نوشتن نیست.', 500 ); }
		@chmod( $temp, 0600 );
		$handle = fopen( $temp, 'wb' );
		$headers = null; $mapping = array(); $source_index = null; $provider_index = null; $provider_values = array(); $tipax_sources = 0; $preview = array(); $total = 0; $bytes = 0; $started = microtime( true );
		try {
			foreach ( TableReader::rows( $path, $type ) as $row ) {
				if ( microtime( true ) - $started > 15 ) { throw new \RuntimeException( 'آماده‌سازی بیش از حد طول کشید؛ فایل را به چند CSV کوچک‌تر تقسیم کنید.' ); }
				if ( null === $headers ) {
					$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] );
					$headers = array_map( 'trim', $row );
					if ( in_array( '', $headers, true ) || count( array_unique( $headers ) ) !== count( $headers ) ) { throw new \RuntimeException( 'ردیف اول باید نام ستون‌های یکتا و غیرخالی داشته باشد.' ); }
					$mapping = ( new ColumnMapper() )->auto_detect_headers( $headers );
					$source_header = array_search( 'source', $mapping, true );
					if ( false !== $source_header ) { $source_index = array_search( $source_header, $headers, true ); }
					$provider_header = array_search( 'provider', $mapping, true );
					if ( false !== $provider_header ) { $provider_index = array_search( $provider_header, $headers, true ); }
					continue;
				}
				$total++;
				if ( $total > 100000 ) { throw new \RuntimeException( 'هر فایل حداکثر ۱۰۰٬۰۰۰ ردیف دارد؛ فایل را تقسیم کنید.' ); }
				// Excel omits trailing empty cells; CSV column mismatches are reported per row.
				if ( 'xlsx' === $type && count( $row ) < count( $headers ) ) { $row = array_pad( $row, count( $headers ), '' ); }
				if ( null !== $provider_index ) {
					$value = trim( (string) ( $row[$provider_index] ?? '' ) ); $key = 'value:' . $value;
					if ( ! isset( $provider_values[$key] ) ) {
						if ( count( $provider_values ) >= 1000 ) { throw new \RuntimeException( 'بیش از ۱۰۰۰ نام متفاوت ارائه‌دهنده در فایل است؛ ستون ارائه‌دهنده را بررسی کنید.' ); }
						$provider_values[$key] = array( 'value' => $value, 'total' => 0 );
					}
					$provider_values[$key]['total']++;
				}
				if ( null !== $source_index ) {
					$host = strtolower( (string) wp_parse_url( (string) ( $row[$source_index] ?? '' ), PHP_URL_HOST ) );
					if ( in_array( $host, array( 'tipaxco.com', 'www.tipaxco.com' ), true ) ) { $tipax_sources++; }
				}
				$entry = array( 'row' => $total + 1, 'values' => $row );
				$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n";
				$bytes += strlen( $line );
				if ( $bytes > 64 * 1024 * 1024 ) { throw new \RuntimeException( 'حجم آماده‌سازی از ۶۴ مگابایت بیشتر است؛ فایل را تقسیم کنید.' ); }
				if ( fwrite( $handle, $line ) !== strlen( $line ) ) { throw new \RuntimeException( 'فضای ذخیره موقت کافی نیست.' ); }
				if ( count( $preview ) < 5 ) { $preview[] = $row; }
			}
			if ( ! $headers || ! $total ) { throw new \RuntimeException( 'فایل فاقد ردیف داده است.' ); }
			fclose( $handle ); $handle = null;
			$data = array( 'path' => $temp, 'headers' => $headers, 'preview' => $preview, 'mapping' => $mapping, 'total' => $total, 'processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'warnings' => 0, 'issues' => array(), 'offset' => 0 );
			if ( null !== $provider_index ) { $data['provider_values'] = array_values( $provider_values ); }
			if ( $tipax_sources === $total ) { $data['provider_hint'] = 'tipax'; }
			global $wpdb;
			$now = current_time( 'mysql', true );
			$ok = $wpdb->insert( self::table(), array( 'user_id' => get_current_user_id(), 'filename' => $name, 'status' => 'preview', 'data' => wp_json_encode( $data ), 'created_at' => $now, 'updated_at' => $now ) );
			if ( ! $ok ) { throw new \RuntimeException( 'ثبت عملیات ناموفق بود.' ); }
			return self::get_public( (int) $wpdb->insert_id );
		} catch ( \Throwable $e ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			wp_delete_file( $temp );
			return self::error( $e->getMessage() );
		}
	}
	private static function locked( int $id, callable $callback ) {
		global $wpdb;
		$lock = 'tapin-' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) { return self::error( 'عملیات دیگری در حال اجراست؛ دوباره تلاش کنید.', 409 ); }
		try {
			$job = self::get( $id );
			if ( ! $job ) { return self::error( 'عملیات پیدا نشد.', 404 ); }
			return $callback( $job );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public static function start( int $id, array $options ) {
		return self::locked( $id, static function( $job ) use ( $options, $id ) {
			if ( 'preview' !== $job['status'] ) { return self::error( 'این عملیات قبلاً شروع شده است.', 409 ); }
			$mapping = $job['data']['mapping'] ?? array();
			if ( ! is_array( $mapping ) ) { return self::error( 'نگاشت ستون‌ها نامعتبر است.' ); }
			$used = array();
			foreach ( $mapping as $header => $field ) {
				if ( ! in_array( $header, $job['data']['headers'], true ) || ! in_array( $field, array_merge( self::FIELDS, array( '' ) ), true ) ) { return self::error( 'نگاشت ستون‌ها نامعتبر است.' ); }
				if ( '' !== $field ) {
					if ( in_array( $field, $used, true ) ) { return self::error( 'هر فیلد فقط به یک ستون نگاشت شود.' ); }
					$used[] = $field;
				}
			}
			$missing = array_diff( array( 'name', 'address' ), $used );
			if ( $missing ) {
				$labels = array( 'name' => 'نام شعبه (نام شعبه یا عنوان نمایندگی)', 'address' => 'نشانی (آدرس یا نشانی)' );
				return self::error( 'ستون شناسایی‌نشده: ' . implode( '، ', array_intersect_key( $labels, array_flip( $missing ) ) ) . '؛ عنوان ستون فایل را اصلاح و دوباره بارگذاری کنید.' );
			}
			if ( ! in_array( $options['duplicate_action'] ?? '', array( 'skip', 'update' ), true ) ) { return self::error( 'روش برخورد با تکرار را انتخاب کنید.' ); }
			$per_row_provider = in_array( 'provider', $used, true );
			$provider = $per_row_provider ? 0 : absint( $options['provider_id'] ?? 0 );
			$provider_mapping = $options['provider_mapping'] ?? array();
			if ( ! is_array( $provider_mapping ) ) { return self::error( 'تطبیق ارائه‌دهندگان نامعتبر است.' ); }
			if ( $per_row_provider && $provider_mapping ) {
				$providers = ( new ProviderRepository() )->get_all( false );
				$values = array_column( $job['data']['provider_values'] ?? array(), 'value' );
				foreach ( $provider_mapping as $value => $chosen ) {
					if ( '' === trim( (string) $value ) || ! in_array( (string) $value, $values, true ) || ! is_scalar( $chosen ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $chosen ) || ! ProviderResolver::resolve( (string) $value, $providers, array( $value => $chosen ) ) ) { return self::error( 'برای «' . (string) $value . '» یک ارائه‌دهندهٔ ثبت‌شده و مطابق با فایل انتخاب کنید.' ); }
				}
			}
			if ( ! $per_row_provider ) {
				$provider_record = ( new ProviderRepository() )->get_by_id( $provider );
				if ( ! $provider_record ) { return self::error( 'ارائه‌دهنده معتبر انتخاب کنید.' ); }
				if ( ! empty( $job['data']['provider_hint'] ) && $job['data']['provider_hint'] !== $provider_record['slug'] ) { return self::error( 'منبع همهٔ ردیف‌ها متعلق به تیپاکس است؛ ارائه‌دهندهٔ تیپاکس را انتخاب کنید.' ); }
			}
			$job['data']['mapping'] = $mapping;
			$job['data']['provider_id'] = $provider;
			$job['data']['provider_mode'] = $per_row_provider ? 'column' : 'selected';
			$job['data']['provider_mapping'] = $per_row_provider ? $provider_mapping : array();
			$job['data']['duplicate_action'] = $options['duplicate_action'];
			$job['status'] = 'running';
			self::save( $job );
			return self::get_public( $id );
		} );
	}
	public static function step( int $id ) {
		return self::locked( $id, static function( $job ) use ( $id ) {
			if ( 'running' !== $job['status'] ) { return self::get_public( $id ); }
			global $wpdb;
			// Fail closed on hosts whose existing tables do not support transactional checkpoints.
			foreach ( array( self::table(), $wpdb->prefix . 'tapin_service_points' ) as $table ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
				if ( 'InnoDB' !== $engine ) { return self::error( 'برای ورود امن داده، جدول‌های افزونه باید از نوع InnoDB باشند.', 500 ); }
			}
			if ( ! is_readable( $job['data']['path'] ) ) {
				$job['status'] = 'expired'; self::save( $job );
				return self::error( 'فایل موقت منقضی شده؛ دوباره بارگذاری کنید.', 410 );
			}
			$handle = fopen( $job['data']['path'], 'rb' );
			if ( ! $handle || 0 !== fseek( $handle, $job['data']['offset'] ) ) { if ( $handle ) { fclose( $handle ); } return self::error( 'خواندن فایل موقت ناموفق بود.', 500 ); }
			$wpdb->query( 'START TRANSACTION' );
			try {
				$started = microtime( true ); $count = 0;
				$mapper = new ColumnMapper( $job['data']['mapping'] );
				$providers = ( new ProviderRepository() )->get_all( false );
				$provider_ids = array_fill_keys( array_map( static fn( $item ) => (int) $item['id'], $providers ), true );
				while ( $count < 50 && microtime( true ) - $started < 2 && false !== ( $line = fgets( $handle ) ) ) {
					$entry = json_decode( $line, true );
					if ( ! is_array( $entry ) ) { throw new \RuntimeException( 'فایل موقت خراب است.' ); }
					if ( count( $entry['values'] ) !== count( $job['data']['headers'] ) ) {
						$outcome = array( 'result' => 'failed', 'messages' => array( 'تعداد ستون‌های ردیف با سربرگ برابر نیست.' ) );
					} else {
						$raw = $mapper->map_row( array_combine( $job['data']['headers'], $entry['values'] ) );
						$provider_value = trim( (string) ( $raw['provider'] ?? '' ) );
						$provider = 'column' === ( $job['data']['provider_mode'] ?? 'selected' ) ? ProviderResolver::resolve( $provider_value, $providers, $job['data']['provider_mapping'] ?? array() ) : (int) $job['data']['provider_id'];
						unset( $raw['provider'] );
						if ( ! $provider ) {
							$outcome = array( 'result' => 'failed', 'messages' => array( 'ارائه‌دهندهٔ «' . ( '' === $provider_value ? '(خالی)' : $provider_value ) . '» ناشناخته یا مبهم است؛ یک ارائه‌دهندهٔ ثبت‌شده و مطابق با فایل انتخاب کنید.' ) );
						} elseif ( ! isset( $provider_ids[$provider] ) ) {
							throw new \RuntimeException( 'ارائه‌دهنده حذف شده است؛ عملیات را لغو کنید.' );
						} else {
							$outcome = RowProcessor::process( $raw, $provider, $job['data']['duplicate_action'], $id );
						}
					}
					$job['data'][$outcome['result']]++;
					$job['data']['processed']++; $count++;
					if ( $outcome['messages'] ) {
						if ( in_array( $outcome['result'], array( 'inserted', 'updated' ), true ) ) { $job['data']['warnings']++; }
						if ( count( $job['data']['issues'] ) < 1000 ) { $job['data']['issues'][] = array_merge( array( 'row' => $entry['row'] ), $outcome ); }
					}
				}
				$job['data']['offset'] = ftell( $handle );
				if ( $job['data']['processed'] === $job['data']['total'] ) { $job['status'] = 'completed'; }
				self::save( $job );
				if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'ثبت نهایی دسته ناموفق بود.' ); }
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( $e->getMessage(), 500 );
			} finally { fclose( $handle ); }
			if ( 'completed' === $job['status'] ) { \Tapin\ServicePointLocator\Service\OperationalLog::record( 'import_completed', array_merge( $job['data'], array( 'job_id' => $id ) ) ); wp_delete_file( $job['data']['path'] ); }
			return self::get_public( $id );
		} );
	}
	public static function cancel( int $id ) {
		return self::locked( $id, static function( $job ) use ( $id ) {
			if ( in_array( $job['status'], array( 'running', 'preview' ), true ) ) {
				$job['status'] = 'cancelled'; self::save( $job ); wp_delete_file( $job['data']['path'] );
			}
			return self::get_public( $id );
		} );
	}
	/** Remove import history and undo point changes that still match this import. */
	public static function delete( int $id ) {
		return self::locked( $id, static function( $job ) {
			global $wpdb;
			if ( 'running' === $job['status'] ) { return self::error( 'عملیات در حال اجراست؛ ابتدا آن را لغو کنید.', 409 ); }
			$path = $job['data']['path'] ?? '';
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'شروع حذف امن فایل ناموفق بود.', 500 ); }
			try {
				$undo = ImportPointLinks::undo( (int) $job['id'] );
				if ( 1 !== (int) $wpdb->delete( self::table(), array( 'id' => (int) $job['id'] ), array( '%d' ) ) ) { throw new \RuntimeException( 'حذف سابقه فایل انجام نشد.' ); }
				$remaining_jobs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('completed','cancelled')" );
				if ( 0 === $remaining_jobs ) {
					$points_table = Schema::get_service_points_table();
					$unlinked = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $points_table . ' p LEFT JOIN ' . ImportPointLinks::table() . ' l ON l.point_id = p.id WHERE l.id IS NULL' );
					if ( $unlinked > 0 ) {
						$wpdb->query( 'DELETE g FROM ' . $wpdb->prefix . 'tapin_geocoding_jobs g INNER JOIN ' . $points_table . ' p ON p.id = g.point_id LEFT JOIN ' . ImportPointLinks::table() . ' l ON l.point_id = p.id WHERE l.id IS NULL' );
						$deleted_legacy = $wpdb->query( 'DELETE p FROM ' . $points_table . ' p LEFT JOIN ' . ImportPointLinks::table() . ' l ON l.point_id = p.id WHERE l.id IS NULL' );
						if ( false === $deleted_legacy ) { throw new \RuntimeException( 'پاک‌کردن شعب قدیمی بدون سابقه فایل ناموفق بود.' ); }
						$undo['deleted_points'] += (int) $deleted_legacy;
						$undo['deleted_legacy_points'] = (int) $deleted_legacy;
					}
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'ثبت نهایی حذف فایل انجام نشد.' ); }
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( $e->getMessage(), 500 );
			}
			if ( is_string( $path ) && '' !== $path ) { wp_delete_file( $path ); }
			$undo['untracked_legacy'] = ( ! empty( $job['data']['inserted'] ) || ! empty( $job['data']['updated'] ) ) && 0 === $undo['tracked_points'];
			return array_merge( array( 'deleted' => true ), $undo );
		} );
	}
	public static function cleanup(): void {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status IN ('preview','running') AND updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100" );
		foreach ( $ids ?: array() as $id ) {
			self::locked( (int) $id, static function( $job ) {
				if ( strtotime( $job['updated_at'] . ' UTC' ) < time() - DAY_IN_SECONDS ) {
					$job['status'] = 'expired'; self::save( $job ); wp_delete_file( $job['data']['path'] );
				}
			} );
		}
	}
}
