"""Run only providers.py/records.py with a non-importable storage double.

Usage: python -I -B tests/price-check-python-fixtures.py <sibling-source> <output-json>
Never imports storage/db, opens .env, connects to SQL/HTTP, or uses saved records.
"""
import copy
from collections import Counter
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import sys
import types

sys.dont_write_bytecode = True
source = Path(sys.argv[1]).resolve()
shops = []
def no_storage(*args, **kwargs):
    raise AssertionError("Storage access is outside the pure parity harness")
storage = types.ModuleType("storage")
storage.list_shops = lambda: copy.deepcopy(shops)
storage.__getattr__ = lambda name: no_storage
sys.modules["storage"] = storage
sys.modules["db"] = types.ModuleType("db")
modules = {}
for name in ("providers", "records"):
    path = source / (name + ".py")
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    modules[name] = module
p, r = modules["providers"], modules["records"]
cases = []
def add(name, op, *args):
    cases.append({"name": name, "op": op, "args": copy.deepcopy(args)})
def config(**changes):
    base = {"id": 1, "name": "fixture", "params": [{"id": 10, "name": "amount", "label": "مبلغ", "input": "number", "type": "int", "visible": True, "default": 12}], "location_config": {"origin": {"enabled": False}}}
    base.update(changes)
    return base
def provider(**changes):
    return r.normalise(config(**changes))

# Configuration normalization, exact flags/defaults/error order and Unicode bounds.
for value in (None, [], "", {}, {"name": "fixture"}, config(), config(active=0, id=True, display_name="0", shop_id=0), config(price_currency_in=" TOMAN ", weight_unit_out="Kg")):
    add("configuration shape/flags " + str(len(cases)), "config", value)
for input_kind in ("text", "number", "select", "dict", "list", "map", "unknown"):
    param = {"id": "10", "name": "item", "input": input_kind, "visible": "0", "required": "0", "default": " 0 ", "options": ["0", "a"], "fields": [{"id": "20", "name": "nested", "visible": True, "default": "0", "input": "select", "options": ["0"]}], "min_items": True}
    add("dynamic input " + input_kind, "config", config(params=[param]))
for kind in ("city", "province", "money", "weight", "unknown"):
    for input_kind in ("text", "select", "map", "list"):
        add("kind " + kind + "/" + input_kind, "config", config(params=[{"name": "field", "input": input_kind, "kind": kind, "options": [], "fields": [{"name": "sub", "kind": kind, "input": input_kind}]}]))
for raw in (None, {}, [None, {}, " ", "0", {"value": 0}, {"value": "0"}], [1, True], ["a", "a"]):
    add("select options " + str(len(cases)), "config", config(params=[{"name": "choice", "input": "select", "options": raw, "default": "0"}]))
for raw in (None, {}, [{"name": ""}], [None], [{"name": "a"}, {"name": "a"}], [{"name": "origin_city_id"}], [{"name": "x", "description": "ش" * 501}], [{"name": "x", "default": {"a": True}}, {"name": "y", "default": [None, 0, "x"]}]):
    add("params shape " + str(len(cases)), "config", config(params=raw))
for value in (None, " ", True, 0, 0.999, 1, "1.5", 300, 300.001, [], "invalid"):
    add("timeout " + str(value), "config", config(timeout=value))
for value in ("", "relative", "ftp://example.test", "HTTPS://example.test/path", "https:///path", "https://example.test:bad", "https://example.test:99999", "data:image/png,fixture"):
    add("endpoint " + value, "config", config(check_price_url=value))
    add("logo " + value, "config", config(logo=value))
add("logo bound", "config", config(logo="x" * 1500001))
for value in (None, [], {}, {"enabled": "yes"}, {"enabled": 1.0, "title": "0", "url": "https://example.test"}, {"enabled": False, "title": "ش" * 121, "url": "x" * 501, "description": "ش" * 501}):
    add("referral " + str(len(cases)), "config", config(referral=value))
for value in (None, {}, [None, {}, {"key": "0", "label": "0"}], [{"key": "entries.total"}, {"key": "entries.total"}]):
    add("price fields " + str(len(cases)), "config", config(price_fields=value, main_price_key="entries.total"))
for value in (None, "amount", "missing", "amount.sub", "origin_province_id"):
    add("unit targets " + str(value), "config", config(price_input_param=value, weight_input_param=value))
for raw in (None, [], {}, {"mode": "invalid"}, {"mode": "combined"}, {"mode": "combined", "param": "amount"}, {"mode": "split", "lat_param": "same", "long_param": "same"}):
    add("map configuration " + str(len(cases)), "config", config(params=[{"name": "point", "input": "map", "map": raw}, {"name": "amount"}]))
add("map-to-map collision", "config", config(params=[{"name": "a", "input": "map"}, {"name": "b", "input": "map"}]))
for value in (None, [], {}, {"origin": {"enabled": 0}}, {"origin": {"enabled": False}, "destination": {"enabled": True, "display": "combined", "province_param": "amount", "city_param": "amount"}}, {"destination": {"enabled": True}}, {"custom_locations": {"provinces": [{"code": 0, "title": "0"}, {"code": 0}], "cities": [None, {"code": "1"}, {"code": "2", "province_code": "99"}]}}, {"blocked_locations": {"provinces": [{}], "cities": {}}}):
    add("location config " + str(len(cases)), "config", config(location_config=value))
add("location bounds", "config", config(location_config={"custom_locations": {"provinces": [{"code": "x" * 31}, {"code": "ok", "title": "ش" * 121}], "cities": [{"code": "ok", "title": "ش" * 121, "province_code": "external"}]}}))
add("province count bound", "config", config(location_config={"custom_locations": {"provinces": [{"code": str(i)} for i in range(501)]}}))
add("city count bound", "config", config(location_config={"custom_locations": {"cities": [{"code": str(i), "province_code": "1"} for i in range(20001)]}}))
for op in r.RULE_OPS + ("invalid",):
    add("rule definition " + op, "config", config(rules=[{"name": "نام", "if_field": "amount", "if_op": op, "if_value": "5", "if_value2": "2", "then_field": "amount", "then_op": "gte", "then_value": 0}]))
add("rule validation ordering", "config", config(rules=[None, {"if_field": "missing", "if_op": "between", "if_value": "x", "then_field": "amount.missing", "then_op": "in", "message": "ش" * 501, "name": "ش" * 121}]))
add("rule count bound", "config", config(rules=[{"if_field": "amount", "if_op": "gt", "if_value": 0}] * 51))

# Unit coercion/default/rounding cases, including both signed half boundaries.
for op, units in (("currency", ("rial", "toman")), ("weight", ("gram", "kilogram"))):
    for value in (None, "", " ", "0", True, False, 0, 1, -1, 5, -5, 14, 15, -15, 15.01, -15.01, "12.5", "1e2", "1_000", "۱۲۳", "not a number", {}, [], 1000, 1001, 2.5):
        for a, b in ((units[0], units[1]), (units[1], units[0]), (units[0], units[0])):
            add(op + " " + str(len(cases)), op, value, a, b)
for value in (None, 0, True, " TOMAN ", "تومان", "kg", "KILO", "کیلوگرم", "unknown"):
    add("unit defaults " + str(len(cases)), "units", value)
for value in (None, "", "  ", "1", "1.0", 1.0, True, "۱۲", "1_000", {}, []):
    for typ in ("int", "float", "str"):
        add("coerce " + str(len(cases)), "coerce", value, typ)

# Complete payload normalization with supplied, synthetic origin data only.
scalar = provider(params=[{"id": 10, "name": "amount", "label": "مبلغ", "input": "number", "type": "int", "visible": True, "required": True, "kind": "money", "default": 15}, {"id": 11, "name": "hidden", "input": "number", "type": "int", "kind": "weight", "default": 2, "visible": False}], price_currency_in="rial", price_currency_out="toman", weight_unit_in="gram", weight_unit_out="kilogram")
for value in ({}, {"amount": None}, {"amount": ""}, {"amount": " "}, {"amount": 0}, {"amount": True}, {"amount": []}, {"amount": {}}, {"amount": "۱۲"}, {"amount": 1.0}, {"amount": "1e2"}, {"amount": "1_000", "hidden": 999, "unknown": 1}):
    add("scalar payload " + str(len(cases)), "payload", scalar, value, [])
for kind in ("dict", "list"):
    nested = provider(params=[{"id": 12, "name": "items", "label": "اقلام", "input": kind, "min_items": 2, "fields": [{"id": 20, "name": "amount", "input": "number", "type": "float", "visible": True, "required": True, "kind": "money", "default": 2}, {"id": 21, "name": "hidden", "visible": False, "kind": "weight", "default": 1}]}], price_currency_out="toman", weight_unit_out="kilogram")
    add("defaults " + kind, "defaults", nested)
    for value in (None, {}, [], [None], [{}, {"amount": ""}], [{"amount": None}, {"amount": "2.5"}], {"amount": "", "unknown": 0}, {"amount": "2.5", "hidden": 99}):
        add("nested " + kind + " " + str(len(cases)), "payload", nested, {"items": value}, [])
for mode in ("split", "combined"):
    mapped = provider(params=[{"name": "point", "input": "map", "map": {"mode": mode, "lat_param": "latitude", "long_param": "longitude", "param": "coords"}}])
    for value in (None, [], {}, {"lat": "35.7", "long": "51"}, {"lat": "1e-5", "long": "1e16"}, {"lat": True, "long": {}}, {"lat": "bad"}):
        add("map payload " + mode + " " + str(len(cases)), "payload", mapped, {"point": value}, [])
origin = r.normalise({"id": 3, "name": "origin-fixture", "params": [{"name": "shop_id", "default": "101"}, {"name": "amount"}]})
origin_shops = [{"id": "101", "title": "اول", "province": "1", "city": "2"}, {"id": "102", "title": "دوم", "province": "1", "city": "3"}]
for value in ({}, {"origin_province_id": "1", "origin_city_id": "3"}, {"origin_province_id": "01", "origin_city_id": "99"}, {"origin_province_id": "9"}):
    add("origin supplied-data " + str(len(cases)), "payload", origin, value, origin_shops)
origin_no_default = r.normalise({"name": "origin-fixture", "params": [{"name": "amount"}]})
add("origin missing", "payload", origin_no_default, {}, [])
for op in r.RULE_OPS:
    for actual in (None, "", 0, "0", 4, 5, 6, "bad", True):
        add("rule evaluation " + str(len(cases)), "rule_clause", op, actual, "5" if op != "in" else "4, 5", "2")
for rule in ({"if_field": "amount", "if_op": "gt", "if_value": "5"}, {"if_field": "amount", "if_op": "gt", "if_value": "5", "then_field": "hidden", "then_op": "lte", "then_value": "10"}, {"if_field": "amount", "if_op": "eq", "if_value": 0, "message": "0"}):
    for incoming in ({}, {"amount": 1}, {"amount": 6}):
        q = copy.deepcopy(scalar); q["rules"] = [rule]
        add("conditional payload " + str(len(cases)), "payload", q, incoming, [])

# Bulk stable IDs, sources/mirrors, translations, variant constraints/error phases.
bulk_provider = provider(params=[{"id": 10, "name": "amount", "visible": True, "type": "int"}, {"id": 11, "name": "choice", "input": "select", "options": ["a", "b"], "visible": True}, {"id": 12, "name": "items", "input": "dict", "fields": [{"id": 20, "name": "sub", "input": "select", "options": ["x", "y"]}]}, {"id": 13, "name": "point", "input": "map"}])
second = copy.deepcopy(bulk_provider); second["id"] = 2; second["name"] = "second"; second["active"] = False
provider_list = [bulk_provider, second]
for value in ("not-an-option", " a "):
    add("select submission passthrough " + str(len(cases)), "payload", bulk_provider, {"choice": value}, [])
def bulk(**changes):
    value = {"providers": ["fixture", "1", 1, True, 2], "fields": [{"name": "shared", "label": "0"}], "location": {"destination": {"enabled": False}}, "mappings": {"1": {"shared": "amount"}, "2": {"shared": "12.20"}}}
    value.update(changes); return value
for raw in (None, [], {}, bulk(), bulk(providers=[None, 999, 1.0]), bulk(fields={}), bulk(fields=[None, {"name": "9bad"}, {"name": "_valid"}, {"name": "_valid"}]), bulk(mappings=[]), bulk(mappings={"fixture": {"shared": "amount"}}), bulk(mappings={"1": None, "fixture": {"shared": "amount"}}), bulk(location={}), bulk(price={"field": "missing"}, weight={"field": "shared", "unit": "KG"})):
    add("bulk config " + str(len(cases)), "bulk", raw, provider_list)
for ref in (None, {}, [], {"provider": "missing", "param": 10}, {"provider": 1, "param": "items"}, {"provider": 1, "param": "items.sub"}, {"provider": 1, "param": "choice"}, {"provider": 1, "param": "point"}, {"provider": 1, "param": None}):
    for key in ("source", "mirror"):
        add("bulk ref " + str(len(cases)), "bulk", bulk(fields=[{"name": "shared", key: ref}]), provider_list)
for value in (None, [], {"unknown": {}}, {"1": []}, {"1": {"missing": {"a": "b"}}}, {"1": {"shared": []}}, {"1": {"shared": {"a": "b", "empty": " "}}}, {"1": {"shared": {"missing": "a"}}}):
    add("value mappings " + str(len(cases)), "bulk", bulk(fields=[{"name": "shared", "source": {"provider": 1, "param": 11}}], value_mappings=value), provider_list)
add("mirror forbids translations", "bulk", bulk(fields=[{"name": "shared", "mirror": {"provider": 1, "param": 10}}], value_mappings={"2": {"shared": {"a": "b"}}}), provider_list)
for variants in (None, [], {"1": []}, {"1": {"amount": [1]}}, {"1": {"choice": ["a", "a", "b"]}}, {"1": {"choice": ["invalid"]}}, {"1": {"items": ["x"]}}, {"1": {"items.sub": ["x", "y"]}}, {"1": {"point": ["x"]}}, {"1": {"choice": [None, True]}}, {"1": {"choice": "a"}}, {"1": {"missing": [1]}}, {"1": {str(i): [1] for i in range(6)}}):
    add("variants " + str(len(cases)), "bulk", bulk(variants=variants), provider_list)
add("variant bound", "bulk", bulk(mappings={}, variants={"1": {"amount": list(range(11))}}), provider_list)
add("combined variant bound", "bulk", bulk(mappings={}, variants={"1": {"amount": list(range(10)), "choice": ["a", "b"], "items.sub": ["x", "y"]}}), provider_list)
add("variant error phase", "bulk", bulk(fields=[], location={}, variants=[]), provider_list)
add("active providers", "active", bulk(), provider_list)
for selected in (True, 1, 1.0, "12", {"1": True}, False, 0):
    add("bulk selection shape " + str(len(cases)), "bulk", bulk(providers=selected), provider_list)
for target in (None, True, 10, 10.0, "10", "amount", "12.20", "items.sub", "items.missing", "missing", "11", "0"):
    for canonical in (False, True): add("target " + str(len(cases)), "target", bulk_provider, target, canonical)

# Additional finite representations, id/whitespace boundaries and source-admitted non-finites.
for value in ("nan", "-Infinity", "1e309"):
    add("non-finite timeout " + value, "config", config(timeout=value))
    add("non-finite coercion " + value, "coerce", value, "float")
    add("non-finite weight " + value, "weight", value, "gram", "kilogram")
    add("non-finite rule " + value, "rule_clause", "gte", value, "5", "2")
    add("non-finite rule definition " + value, "config", config(rules=[{"if_field": "amount", "if_op": "between", "if_value": value, "if_value2": "0"}]))
for value in (1e-4, 0.00012345678901234567, 1e-5, 1e15, 1e16, -1e16, 1e17, 1.2345678901234567e20, -0.0, 1e-6):
    q = provider(params=[{"name": "point", "input": "map", "map": {"mode": "combined", "param": "coords"}}])
    add("float map text " + str(value), "payload", q, {"point": {"lat": value, "long": value}}, [])
for value in (None, True, False, 0, -1, 1.0, 1.5, "-2", "1_000", "۱۲", [], {}):
    add("row id " + str(len(cases)), "config", config(params=[{"id": value, "name": "field"}]))
for default in (True, False, 0, "0", ["a'b", 'a"b'], {"a": None}):
    add("default representation " + str(len(cases)), "config", config(params=[{"name": "field", "default": default}]))
for value in ("\u00a0", "\u001c", "\u2003", "\u00a01\u00a0"):
    add("Unicode whitespace " + str(len(cases)), "payload", scalar, {"amount": value}, [])
add("variant exactly 20", "bulk", bulk(mappings={}, variants={"1": {"amount": list(range(10)), "choice": ["a", "b"]}}), provider_list)
add("variant empty select options", "bulk", bulk(mappings={}, variants={"1": {"choice": ["a"]}}), [{**bulk_provider, "params": [{**param, "options": []} if param["name"] == "choice" else param for param in bulk_provider["params"]]}])
for rules, incoming in (([{"if_field": "items.sub", "if_op": "gte", "if_value": 2}], {"items": [{"sub": 2}, {"sub": 1}]}), ([{"if_field": "items.sub", "if_op": "eq", "if_value": 2, "then_field": "items.sub", "then_op": "gte", "then_value": 2}], {"items": [{"sub": 2}, {"sub": 1}]})):
    q = provider(params=[{"name": "items", "label": "اقلام", "input": "list", "fields": [{"name": "sub", "label": "زیرفیلد", "visible": True, "type": "int"}]}], rules=rules)
    add("nested rule " + str(len(cases)), "payload", q, incoming, [])

for value in (float("nan"), float("inf"), -float("inf")):
    add("non-finite row id " + str(value), "config", config(params=[{"id": value, "name": "field"}]))
for ref in (None, True, 10, 10.0, 10.5, "۱۰", float("nan"), float("inf"), "amount"):
    add("find param " + str(len(cases)), "find", bulk_provider, ref)
for value in (None, [], {}, {"mappings": []}, {"mappings": {"1": {"shared": "10"}}}, {"mappings": {"1": None}}, {"mappings": {"fixture": {"shared": "10"}}}):
    add("mapping row " + str(len(cases)), "mapping_row", value, 1)
for value in ("9223372036854775807", "-9223372036854775808"):
    add("64-bit integer " + value, "coerce", value, "int")
add("currency integer product boundary", "currency", 922337203685477580, "toman", "rial")
add("weight integer product boundary", "weight", 9223372036854775, "kilogram", "gram")
for value in (4.999999999, 5.0, 5.000000001, -4.999999999, -5.0, -5.000000001):
    add("currency half boundary " + str(value), "currency", value, "rial", "toman")
for value in (["\b\x00\x7f\u00a0\u200b\U000e0001", "\n\r\t"], {"emoji": "😀", "quote": "a'b\\c"}):
    add("container repr controls " + str(len(cases)), "config", config(params=[{"name": "field", "default": value}]))
for incoming in ({}, {"origin_province_id": "1", "origin_city_id": "3"}):
    add("payload JSON excludes origin " + str(len(cases)), "payload_json", origin, incoming, origin_shops)
for value in ("\u001c1\u001c", "\u001c1.5\u001c", "\u00a01.5\u00a0", "\u00851.5\u0085"):
    add("numeric constructor whitespace " + str(len(cases)), "coerce", value, "int")
    add("numeric constructor whitespace " + str(len(cases)), "coerce", value, "float")
    add("numeric constructor timeout " + str(len(cases)), "config", config(timeout=value))
    add("numeric constructor id " + str(len(cases)), "config", config(params=[{"id": value, "name": "field"}]))
    add("conversion strips whitespace " + str(len(cases)), "currency", value, "rial", "toman")
for value in ("Kg", "KILO", "Kilogram"):
    add("casefold unit " + value, "units", value)

def execute(case):
    global shops
    args = case["args"]; op = case["op"]
    if op == "config": return r.normalise(*args)
    if op == "bulk": return r.normalise_bulk_config(*args)
    if op == "currency": return p.convert_currency_amount(*args)
    if op == "weight": return p.convert_weight_amount(*args)
    if op == "units": return {"currency": r._currency(args[0]), "weight": r._weight_unit(args[0])}
    if op == "coerce": return p.coerce(*args)
    if op == "target": return (p.canonical_param_target if args[2] else p.resolve_param_target)(*args[:2])
    if op == "find": return p.find_param(*args)
    if op == "mapping_row": return r.bulk_mappings_for(*args)
    if op == "defaults": return p.build_defaults(*args)
    if op == "active": return r.bulk_active_providers(*args)
    if op == "rule_clause": return p._rule_clause_holds(*args)
    if op in ("payload", "payload_json"):
        shops = args[2]
        result = p.normalise_payload(*args[:2])
        if op == "payload_json": return json.loads(json.dumps(result, ensure_ascii=False))
        return {"payload": dict(result), "origin": result.origin}
    raise AssertionError(op)
for case in cases:
    try: case["expected"] = {"ok": True, "value": execute(case)}
    except (r.RecordError, p.PayloadError) as exc:
        case["expected"] = {"ok": False, "kind": type(exc).__name__, "message": str(exc), "errors": exc.errors}
    except ValueError as exc:
        case["expected"] = {"ok": False, "kind": "ValueError"}
    except TypeError as exc:
        case["expected"] = {"ok": False, "kind": "TypeError", "message": str(exc)}
def compact(value):
    if isinstance(value, float) and not math.isfinite(value):
        return {"$float": "nan" if math.isnan(value) else ("-inf" if value < 0 else "inf")}
    if isinstance(value, str) and len(value) > 1000 and len(set(value)) == 1:
        return {"$repeat": value[0], "count": len(value)}
    if isinstance(value, list) and len(value) > 500:
        if all(isinstance(row, dict) and row.get("code") == str(i) for i, row in enumerate(value)):
            return {"$sequence": len(value), "row": {**value[0], "code": "$index"}}
    if isinstance(value, (list, tuple)): return [compact(item) for item in value]
    if isinstance(value, dict): return {key: compact(item) for key, item in value.items()}
    return value
# Intern repeated synthetic records/results; fixtures remain inspectable without 40k duplicated lines.
compact_cases = compact(cases)
counts = Counter()
def key(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
def count_nodes(value):
    if isinstance(value, (dict, list)):
        text = key(value)
        if len(text) >= 200: counts[text] += 1
        for item in (value.values() if isinstance(value, dict) else value): count_nodes(item)
count_nodes(compact_cases)
shared = []; indexes = {}
def intern(value, root=False):
    if isinstance(value, (dict, list)):
        text = key(value)
        if not root and counts[text] > 1:
            if text not in indexes:
                indexes[text] = len(shared); shared.append(None)
                shared[indexes[text]] = intern(value, True)
            return {"$ref": indexes[text]}
        if isinstance(value, dict): return {name: intern(item) for name, item in value.items()}
        return [intern(item) for item in value]
    return value
packed_cases = intern(compact_cases, True)
# Record representational differences separately; these are NOT claimed as parity cases.
compatibility = []
for name, op, args in (
    ("arbitrary precision integer", "coerce", ["9223372036854775808", "int"]),
    ("currency integer overflow", "currency", [922337203685477581, "toman", "rial"]),
    ("weight integer overflow", "weight", [9223372036854776, "kilogram", "gram"]),
    ("supplementary decimal digits", "coerce", ["𝟙", "int"]),
    ("infinite currency integer conversion", "currency", ["1e309", "rial", "toman"]),
):
    entry = {"name": name, "op": op, "args": args, "php_exception": "DomainException"}
    try: entry["python_value_repr"] = repr(execute(entry))
    except OverflowError as exc: entry["python_exception"] = type(exc).__name__; entry["python_message"] = str(exc)
    compatibility.append(entry)
result = {"source_sha256": {name: hashlib.sha256((source / (name + ".py")).read_bytes()).hexdigest() for name in modules}, "python": sys.version.split()[0], "shared": shared, "cases": packed_cases, "compatibility_limits": compatibility}
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
print(f"Captured {len(cases)} original-Python cases with storage/db replaced; no external calls or credentials.")
