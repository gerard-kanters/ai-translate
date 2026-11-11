#!/usr/bin/env python3
"""
Model benchmark for AI Translate default models. Maintainer tool: not shipped with the plugin.

Picks, per provider, the cheapest model that translates well in many languages and does not think.

Stages (each resumes from the results cache, so a rerun costs nothing extra):
  candidates  list the models that survive the automatic filters (no cost)
  screen      3 calls per candidate: measures thinking, and drops clearly weak models
  full        25 languages per survivor: quality (chrF++), failures, cost, latency
  report      ranks the results and writes default-models.json

Key: env OPENROUTER_API_KEY, or the file /root/.openrouter-benchmark-key. Never printed.
Test data: FLORES-200 devtest (CC-BY-SA), default /root/benchmark-data/flores200_dataset/devtest.

  python benchmark.py candidates
  python benchmark.py screen --budget 1.5
  python benchmark.py full --budget 5
  python benchmark.py report
"""
import argparse
import json
import os
import random
import re
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

PROVIDERS = {
    'openrouter': {'base': 'https://openrouter.ai/api/v1', 'env': 'OPENROUTER_API_KEY',
                   'key_file': '/root/.openrouter-benchmark-key', 'prices_in_list': True},
    'deepseek': {'base': 'https://api.deepseek.com', 'env': 'DEEPSEEK_API_KEY',
                 'key_file': '/root/.deepseek-benchmark-key', 'prices_in_list': False},
    'groq': {'base': 'https://api.groq.com/openai/v1', 'env': 'GROQ_API_KEY',
             'key_file': '/root/.groq-benchmark-key', 'prices_in_list': False},
}
PROVIDER = 'openrouter'
BASE = PROVIDERS[PROVIDER]['base']
KEY_FILE = PROVIDERS[PROVIDER]['key_file']
FLORES = '/root/benchmark-data/flores200_dataset/devtest'
OUT = '/root/benchmark-out'
PRICES_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'prices.json')

# FLORES code -> (plugin code, English name, weight). Large languages weigh double.
LANGS = {
    'nld_Latn': ('nl', 'Dutch', 2), 'deu_Latn': ('de', 'German', 2), 'fra_Latn': ('fr', 'French', 2),
    'spa_Latn': ('es', 'Spanish', 2), 'ita_Latn': ('it', 'Italian', 2), 'por_Latn': ('pt', 'Portuguese', 2),
    'rus_Cyrl': ('ru', 'Russian', 2), 'zho_Hans': ('zh', 'Chinese (Simplified)', 2), 'jpn_Jpan': ('ja', 'Japanese', 2),
    'kor_Hang': ('ko', 'Korean', 2), 'arb_Arab': ('ar', 'Arabic', 2), 'hin_Deva': ('hi', 'Hindi', 2),
    'tur_Latn': ('tr', 'Turkish', 2), 'pol_Latn': ('pl', 'Polish', 2), 'ind_Latn': ('id', 'Indonesian', 2),
    'swe_Latn': ('sv', 'Swedish', 1), 'fin_Latn': ('fi', 'Finnish', 1), 'ces_Latn': ('cs', 'Czech', 1),
    'ell_Grek': ('el', 'Greek', 1), 'ukr_Cyrl': ('uk', 'Ukrainian', 1), 'tha_Thai': ('th', 'Thai', 1),
    'vie_Latn': ('vi', 'Vietnamese', 1), 'ron_Latn': ('ro', 'Romanian', 1), 'hun_Latn': ('hu', 'Hungarian', 1),
    'heb_Hebr': ('he', 'Hebrew', 1),
}
SOURCES = {'eng_Latn': 'English', 'nld_Latn': 'Dutch', 'deu_Latn': 'German'}
SCREEN_TARGETS = ['nld_Latn', 'deu_Latn', 'jpn_Jpan']
BATCH = 8
BATCHES_FULL = 3
MAX_TOKENS = 4096

# Models that are not general translation models: code, roleplay, vision, safety, tooling.
NAME_DROP = re.compile(
    r'coder|codestral|devstral|-vl|vision|omni|guard|safety|morph|relace|-rp|roleplay|euryale|mythomax|slerp|'
    r'unslop|cydonia|skyfall|weaver|dolphin|moondream|ui-tars|embed|scaledown|schematron|voxtral|sonar|aion|'
    r'lunaris|glimpse|particle|ripple|hermes|bonsai|midm|perceptron|longcat|nex-agi|ling-|inkling|motif|'
    r'audio|image|tts|transcribe|lyria|veo|-search|deep-research|-instruct-2501', re.I)

SYSTEM = (
    'You are a professional translation engine. Translate text from {s} to {t}.\n\n'
    'MANDATORY REQUIREMENTS:\n'
    '- The input text is in {s}. You MUST translate every segment to {t}. NEVER return source text unchanged.\n'
    '- Do NOT analyze or detect the language. Translate from {s} to {t} as instructed.\n'
    '- Every word must be translated. The output MUST be entirely in {t}.\n\n'
    'TRANSLATION QUALITY STANDARDS:\n'
    '- Preserve the original meaning, intent, nuance, and tone of the {s} source text. Do NOT add or remove information.\n'
    '- Produce grammatically correct, idiomatic, and natural text that reads as if originally written in {t} by a native speaker.\n'
    '- Avoid literal, word-for-word translations. Render concepts in culturally natural and linguistically appropriate expressions.\n'
    '- Preserve HTML tags, placeholders such as %s or {{name}}, URLs and numbers exactly as they are.\n\n'
    'OUTPUT FORMAT:\n'
    '- Return ONLY valid JSON: {{"translations": {{"<id>": "<translated_text>"}}}}\n'
    '- Every segment ID must have translated text in {t}.\n'
    '- No explanations, comments, or text outside the JSON.\n'
    '- The translated_text MUST be in {t}, not in {s}.'
)
INSTRUCTION = (
    'Translate every segment from the source language to the target language. You MUST translate every segment. '
    'Do NOT return the source text unchanged. Return ONLY valid JSON in this exact format: '
    '{"translations": {"<id>": "<translated_text>"}}. Every segment ID must have a translated text in the target '
    'language. The translated_text must be in the target language, not the source language. '
    'No extra text, explanations, or comments outside the JSON.'
)

# Formatting fidelity: markup, placeholders, URLs and numbers that must survive translation.
FIDELITY = [
    'Click <strong>Save</strong> to keep your changes.',
    'Hello %s, your order #%d has been shipped.',
    'Visit <a href="https://example.com/help">our help center</a> for more information.',
    'The price is 49.95 euro, including 21% VAT.',
    'Use the {name} placeholder in the subject line.',
    'Read more: <em>terms</em> &amp; <em>privacy</em>.',
]
FIDELITY_TOKENS = [
    ['<strong>', '</strong>'], ['%s', '%d'], ['<a href="https://example.com/help">', '</a>'],
    ['49.95', '21%'], ['{name}'], ['<em>', '</em>', '&amp;'],
]

_lock = threading.Lock()
INNER_WORKERS = 6  # parallel calls per model in the full stage


def load_key():
    key = os.environ.get(PROVIDERS[PROVIDER]['env'], '').strip()
    if not key and os.path.exists(KEY_FILE):
        key = open(KEY_FILE).read().strip()
    if not key:
        sys.exit('No API key: set %s or create %s' % (PROVIDERS[PROVIDER]['env'], KEY_FILE))
    return key


def list_prices(provider=None):
    """Provider list prices (USD per 1M tokens) for providers whose model list has none."""
    try:
        return json.load(open(PRICES_FILE)).get(provider or PROVIDER, {})
    except (OSError, ValueError):
        return {}


def http(path, body=None, key=None, timeout=150, base=None):
    req = urllib.request.Request(
        (base or BASE) + path, data=json.dumps(body).encode() if body is not None else None,
        headers=dict({'User-Agent': 'ai-translate-model-benchmark/1.0', 'Content-Type': 'application/json'},
                     **({'Authorization': 'Bearer ' + key} if key else {})))
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        raw = e.read().decode('utf-8', 'replace')
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {'error': {'message': raw[:300]}}
    except Exception as e:  # network, timeout
        return 0, {'error': {'message': str(e)[:300]}}


# ---------------------------------------------------------------- candidates

def candidates(args):
    if not PROVIDERS[PROVIDER]['prices_in_list']:
        return candidates_plain(args)
    status, data = http('/models')
    rows = data.get('data', []) if status == 200 else []
    if not rows:
        sys.exit('Could not fetch the model list')
    keep = []
    for m in rows:
        mid = m['id']
        a = m.get('architecture', {})
        if a.get('output_modalities') != ['text'] or 'text' not in a.get('input_modalities', []):
            continue
        if ':' in mid or mid.startswith('~') or m.get('expiration_date') or NAME_DROP.search(mid):
            continue
        try:
            p_in, p_out = float(m['pricing']['prompt']) * 1e6, float(m['pricing']['completion']) * 1e6
        except (KeyError, ValueError, TypeError):
            continue
        if p_in <= 0 or p_out <= 0 or p_out > args.max_out_price or p_in > args.max_in_price:
            continue
        if (m.get('reasoning') or {}).get('mandatory'):
            continue
        if (m.get('context_length') or 0) < 32000:
            continue
        keep.append({'id': mid, 'in': p_in, 'out': p_out, 'context': m.get('context_length')})
    keep.sort(key=lambda x: x['out'])
    return keep


PLAIN_DROP = re.compile(r'whisper|orpheus|guard|safeguard|tts|transcribe|embed|moderation|image|audio|realtime', re.I)


def candidates_plain(args):
    """Providers whose model list has no prices: all chat models, priced from prices.json when known."""
    status, data = http('/models', key=load_key())
    rows = data.get('data', []) if status == 200 else []
    if not rows:
        sys.exit('Could not fetch the model list: %s' % json.dumps(data)[:200])
    prices = list_prices()
    keep = []
    for m in rows:
        mid = m['id']
        if PLAIN_DROP.search(mid):
            continue
        p = prices.get(mid) or {}
        keep.append({'id': mid, 'in': p.get('in'), 'out': p.get('out'), 'context': m.get('context_window')})
    keep.sort(key=lambda x: x['out'] if x['out'] is not None else 9e9)
    return keep


def cmd_candidates(args):
    keep = candidates(args)
    for m in keep:
        print('%-55s in %s out %s' % (m['id'], m['in'], m['out']))
    print(len(keep), 'candidates')


# ---------------------------------------------------------------- data

def read_lines(code):
    with open(os.path.join(args_global.flores, code + '.devtest'), encoding='utf-8') as f:
        return [l.rstrip('\n') for l in f]


_cache_lines = {}


def lines(code):
    if code not in _cache_lines:
        _cache_lines[code] = read_lines(code)
    return _cache_lines[code]


def sample_ids():
    return random.Random(42).sample(range(len(lines('eng_Latn'))), BATCH * BATCHES_FULL)


def pick_source(target, batch_no):
    # Mix English and Dutch sources (Dutch is the main user base); never translate a language into itself.
    order = ['eng_Latn', 'nld_Latn', 'eng_Latn'] if batch_no < 3 else ['eng_Latn']
    src = order[batch_no % len(order)]
    if src == target:
        src = 'deu_Latn' if target != 'deu_Latn' else 'eng_Latn'
    return src


# ---------------------------------------------------------------- calls

def results_path(name):
    os.makedirs(OUT, exist_ok=True)
    return os.path.join(OUT, name)


def load_results():
    done = {}
    p = results_path('calls.jsonl')
    if os.path.exists(p):
        for l in open(p, encoding='utf-8'):
            try:
                r = json.loads(l)
                done[r['key']] = r
            except ValueError:
                pass
    return done


def total_cost(done):
    return sum(r.get('cost', 0) or 0 for r in done.values())


def save(rec):
    with _lock:
        with open(results_path('calls.jsonl'), 'a', encoding='utf-8') as f:
            f.write(json.dumps(rec, ensure_ascii=False) + '\n')


def reasoning_tokens(data):
    u = data.get('usage') or {}
    d = u.get('completion_tokens_details') or {}
    t = d.get('reasoning_tokens')
    if not t:
        # Not every provider reports the count: estimate from the returned reasoning text.
        msg = ((data.get('choices') or [{}])[0].get('message') or {})
        text = str(msg.get('reasoning') or '') + str(msg.get('reasoning_content') or '')
        t = max(int(t or 0), len(text) // 4)
    return int(t or 0)


def call(key, model, src_name, tgt_name, segments, extra, provider=None):
    """One translation request shaped like the plugin's. Returns a dict with output, tokens, cost, timing."""
    prov = provider or PROVIDER
    payload = {'segments': [{'id': sid, 'text': txt, 'type': 'text', 'attr': None} for sid, txt in segments],
               'instruction': INSTRUCTION}
    body = {'model': model, 'temperature': 0, 'max_tokens': MAX_TOKENS,
            'messages': [{'role': 'system', 'content': SYSTEM.format(s=src_name, t=tgt_name)},
                         {'role': 'user', 'content': json.dumps(payload, ensure_ascii=False)}]}
    if prov == 'openrouter':
        body['usage'] = {'include': True}
    body.update(extra)
    t0 = time.time()
    status, data = 0, {}
    for attempt in range(3):
        status, data = http('/chat/completions', body, key, base=PROVIDERS[prov]['base'])
        err = json.dumps(data.get('error', ''))[:300].lower()
        if status == 400 and 'temperature' in err and 'temperature' in body:
            del body['temperature']
            continue
        if status in (0, 429, 500, 502, 503, 504) and attempt < 2:
            time.sleep(2 + attempt * 3)
            continue
        break
    rec = {'status': status, 'secs': round(time.time() - t0, 2)}
    if status != 200:
        rec['error'] = json.dumps(data.get('error', ''))[:300]
        rec['mandatory'] = 'mandatory' in rec['error'].lower() or 'cannot be disabled' in rec['error'].lower()
        return rec
    msg = (data.get('choices') or [{}])[0].get('message') or {}
    rec['content'] = msg.get('content') or ''
    rec['finish'] = (data.get('choices') or [{}])[0].get('finish_reason')
    u = data.get('usage') or {}
    rec['tokens_in'] = u.get('prompt_tokens')
    rec['tokens_out'] = u.get('completion_tokens')
    rec['think'] = reasoning_tokens(data)
    rec['cost'] = u.get('cost')
    if rec['cost'] is None:
        # Providers that do not report a cost: tokens times list price (0 when the model has no known price).
        p = list_prices(prov).get(model) or {}
        rec['cost'] = ((rec['tokens_in'] or 0) * (p.get('in') or 0) + (rec['tokens_out'] or 0) * (p.get('out') or 0)) / 1e6
    return rec


def parse(content):
    s = content.replace('```json', '').replace('```JSON', '').replace('```', '')
    a, b = s.find('{'), s.rfind('}')
    if a < 0 or b < a:
        return None
    try:
        t = json.loads(s[a:b + 1]).get('translations')
        return t if isinstance(t, dict) else None
    except (ValueError, AttributeError):
        return None


PROBES = [{'reasoning_effort': 'low'}, {'reasoning_effort': 'none'}, {'reasoning_effort': 'minimal'},
          {'reasoning': {'effort': 'low'}}, {'reasoning': {'effort': 'none'}}, {'reasoning': {'enabled': False}},
          {'thinking': {'type': 'disabled'}}]


def make_job(model, tgt, batch_no, extra_tag, extra, sids, texts_src=None):
    src = pick_source(tgt, batch_no)
    chunk = sids[(batch_no % BATCHES_FULL) * BATCH:(batch_no % BATCHES_FULL) * BATCH + BATCH]
    segs = [(f's{i}', lines(src)[i]) for i in chunk]
    refs = {f's{i}': lines(tgt)[i] for i in chunk}
    return {'key': f'{model}|{src}>{tgt}|{batch_no}|{extra_tag}', 'model': model, 'src': src, 'tgt': tgt,
            'batch': batch_no, 'tag': extra_tag, 'segs': segs, 'refs': refs, 'extra': extra}


def run_job(key, job, done, budget):
    if job['key'] in done:
        return done[job['key']]
    if total_cost(done) >= budget:
        return None
    rec = call(key, job['model'], SOURCES.get(job['src']) or LANGS[job['src']][1], LANGS[job['tgt']][1],
               job['segs'], job['extra'])
    rec.update({'key': job['key'], 'model': job['model'], 'src': job['src'], 'tgt': job['tgt'],
                'batch': job['batch'], 'tag': job['tag'], 'refs': job['refs'],
                'segs': [s[0] for s in job['segs']], 'extra': job['extra']})
    save(rec)
    with _lock:
        done[job['key']] = rec
    return rec


# ---------------------------------------------------------------- scoring

def chrf(hyps, refs):
    from sacrebleu.metrics import CHRF
    if not hyps:
        return 0.0
    return CHRF(word_order=2).corpus_score(hyps, [refs]).score


def score_records(recs):
    """chrF++ per target language over the given call records, plus failure count."""
    per_lang = {}
    fail = total = 0
    for r in recs:
        tgt = r['tgt']
        trans = parse(r.get('content') or '') if r.get('status') == 200 else None
        for sid in r['segs']:
            total += 1
            hyp = ''
            if trans and isinstance(trans.get(sid), str):
                hyp = trans[sid].strip()
            if not hyp:
                fail += 1
            per_lang.setdefault(tgt, ([], []))
            per_lang[tgt][0].append(hyp)
            per_lang[tgt][1].append(r['refs'][sid])
    scores = {t: chrf(h, [x for x in refs]) for t, (h, refs) in per_lang.items()}
    return scores, fail, total


def weighted(scores):
    num = sum(LANGS[t][2] * s for t, s in scores.items())
    den = sum(LANGS[t][2] for t in scores)
    return num / den if den else 0.0


# ---------------------------------------------------------------- screen

def pick_param(key, model, done, budget):
    """Same idea as the plugin's reasoning probe: measure, then find a parameter that lowers thinking."""
    sids = sample_ids()
    # Measure on two languages: a model can stay quiet in Dutch and still think in Japanese.
    bases = {}
    for tgt in ('nld_Latn', 'jpn_Jpan'):
        r = run_job(key, make_job(model, tgt, 0, 'base', {}, sids), done, budget)
        if r is None:
            return None, 'budget'
        bases[tgt] = r
    if bases['nld_Latn'].get('status') != 200:
        return None, 'mandatory' if bases['nld_Latn'].get('mandatory') else 'error'
    worst = max(bases, key=lambda t: bases[t].get('think', 0))
    base = bases[worst]
    if base.get('think', 0) < 20:
        return {}, 'ok'
    for i, extra in enumerate(PROBES):
        j = make_job(model, worst, 0, f'probe{i}', extra, sids)
        r = run_job(key, j, done, budget)
        if r is None:
            return None, 'budget'
        if r.get('status') == 200 and r.get('think', 0) <= base['think'] / 5:
            return extra, 'ok'
    return None, 'thinks'


def screen_model(key, model, done, budget):
    extra, why = pick_param(key, model, done, budget)
    if extra is None:
        return {'model': model, 'ok': False, 'why': why}
    sids = sample_ids()
    jobs = []
    for tgt in SCREEN_TARGETS:
        if extra == {} and tgt in ('nld_Latn', 'jpn_Jpan'):
            tg = 'base'  # already measured by the probe
        else:
            tg = 'p:' + json.dumps(extra, sort_keys=True)
        jobs.append(make_job(model, tgt, 0, tg, extra, sids))
    recs = [run_job(key, j, done, budget) for j in jobs]
    if any(r is None for r in recs):
        return {'model': model, 'ok': False, 'why': 'budget'}
    scores, fail, total = score_records(recs)
    return {'model': model, 'ok': True, 'param': extra, 'screen': weighted(scores), 'fail': fail, 'total': total}


def cmd_screen(args):
    key = load_key()
    done = load_results()
    cands = candidates(args)
    if args.only:
        cands = [c for c in cands if re.search(args.only, c['id'])]
    print(len(cands), 'candidates; spent so far $%.3f of budget $%.2f' % (total_cost(done), args.budget))
    res = []
    with ThreadPoolExecutor(args.workers) as ex:
        futs = {ex.submit(screen_model, key, c['id'], done, args.budget): c for c in cands}
        for f in as_completed(futs):
            r = f.result()
            r.update({'in': futs[f]['in'], 'out': futs[f]['out']})
            res.append(r)
            print(('%-55s ' % r['model']) + (('screen %.1f fail %d/%d param %s' % (r['screen'], r['fail'], r['total'], r['param']))
                                            if r['ok'] else 'dropped: ' + r['why']), flush=True)
    json.dump(res, open(results_path('screen.json'), 'w'), indent=1)
    ok = [r for r in res if r['ok']]
    print('screen done: %d usable, spent $%.3f' % (len(ok), total_cost(done)))


# ---------------------------------------------------------------- full

def survivors(args):
    scr = json.load(open(results_path('screen.json')))
    ok = [r for r in scr if r['ok'] and r['fail'] == 0]
    if not ok:
        sys.exit('No usable screening results')
    best = max(r['screen'] for r in ok)
    # Screening has only 24 sentences: keep a wide band, cheapest first, then cap.
    keep = sorted([r for r in ok if r['screen'] >= best - args.band],
                  key=lambda r: r['out'] if r['out'] is not None else 9e9)
    return keep[:args.top]


def full_model(key, s, done, budget):
    model, extra = s['model'], s['param']
    sids = sample_ids()
    jobs = []
    for tgt in LANGS:
        for b in range(BATCHES_FULL):
            tag = 'full:' + json.dumps(extra, sort_keys=True)
            jobs.append(make_job(model, tgt, b, tag, extra, sids))
    for tgt in ('deu_Latn', 'jpn_Jpan'):
        j = make_job(model, tgt, 0, 'fid:' + json.dumps(extra, sort_keys=True), extra, sids)
        j['key'] += '|fid'
        j['segs'] = [(f'f{i}', t) for i, t in enumerate(FIDELITY)]
        j['refs'] = {}
        j['src'] = 'eng_Latn'
        jobs.append(j)
    # The calls of one model are independent: run them in parallel, so a few finalists are not slow.
    with ThreadPoolExecutor(INNER_WORKERS) as ex:
        results = list(ex.map(lambda j: run_job(key, j, done, budget), jobs))
    return {'model': model, 'complete': all(r is not None for r in results)}


def cmd_full(args):
    key = load_key()
    done = load_results()
    surv = survivors(args)
    print(len(surv), 'finalists; spent so far $%.3f of budget $%.2f' % (total_cost(done), args.budget))
    with ThreadPoolExecutor(args.workers) as ex:
        futs = {ex.submit(full_model, key, s, done, args.budget): s for s in surv}
        for f in as_completed(futs):
            r = f.result()
            print('%-55s %s' % (r['model'], 'done' if r['complete'] else 'stopped (budget)'), flush=True)
    print('full done, spent $%.3f' % total_cost(done))


# ---------------------------------------------------------------- report

def fidelity_rate(recs, texts):
    ok = tot = 0
    for r in recs:
        trans = parse(r.get('content') or '') if r.get('status') == 200 else None
        for i, toks in enumerate(FIDELITY_TOKENS):
            tot += 1
            out = (trans or {}).get(f'f{i}')
            if isinstance(out, str) and all(t in out for t in toks):
                ok += 1
    return ok / tot if tot else 0.0


def cmd_report(args):
    done = load_results()
    by_model = {}
    for r in done.values():
        if str(r['tag']).startswith(('full:', 'fid:')):
            by_model.setdefault(r['model'], []).append(r)
    rows = []
    for model, recs in by_model.items():
        main = [r for r in recs if r['tag'].startswith('full:') and not r['key'].endswith('|fid')]
        fid = [r for r in recs if r['key'].endswith('|fid')]
        if len(main) < len(LANGS) * BATCHES_FULL:
            continue
        scores, fail, total = score_records(main)
        sents = total
        cost = sum(r.get('cost') or 0 for r in recs)
        ok_calls = [r for r in recs if r.get('status') == 200]
        rows.append({
            'model': model, 'quality': weighted(scores), 'fail_rate': fail / total if total else 1,
            'fidelity': fidelity_rate(fid, None), 'cost_per_1000': cost / sents * 1000 if sents else None,
            'secs': statistics.median([r['secs'] for r in ok_calls]) if ok_calls else None,
            'think': statistics.mean([r.get('think', 0) for r in ok_calls]) if ok_calls else 0,
            'weak_langs': sorted(scores, key=scores.get)[:3], 'per_lang': {LANGS[t][0]: round(s, 1) for t, s in scores.items()},
        })
    if not rows:
        sys.exit('No finished models yet: run the full stage first')
    # Gate: no failed segments, no thinking, markup survives. Then quality first: models within the
    # noise (--tie points, about the paired 95% interval) of the best gated model count as tied, and
    # among those the cheapest wins. Lower-quality models follow, best first, as reserves.
    gated = [r for r in rows if r['fail_rate'] == 0 and r['fidelity'] >= 0.8 and r['think'] <= 20]
    best = max((r['quality'] for r in gated), default=0)
    for r in rows:
        r['eligible'] = r in gated and r['quality'] >= best - args.margin
        r['tied'] = r['eligible'] and r['quality'] >= best - args.tie
    cost = lambda r: r['cost_per_1000'] if r['cost_per_1000'] is not None else 9e9
    # Preview, experimental and date-pinned ids get retired: stable ids go first within the same group.
    unstable = lambda r: bool(re.search(r'preview|experimental|[-_]exp\b|beta|-\d{4}$|-\d{8}$|\d{4}-\d{2}-\d{2}$', r['model']))
    rows.sort(key=lambda r: (not r['eligible'], not r['tied'], unstable(r), cost(r)))
    print('best gated quality %.1f; gate = 0 failures, fidelity >=0.8, no thinking; eligible = within %.1f points; '
          'tied (T) = within %.1f points\n' % (best, args.margin, args.tie))
    print('%-3s %-48s %7s %6s %5s %9s %6s %5s' % ('', 'model', 'chrF++', 'fail%', 'fid', '$/1000', 'secs', 'think'))
    for r in rows:
        print('%-3s %-48s %7.1f %6.1f %5.2f %9.4f %6.1f %5.0f' % (
            'T' if r['tied'] else ('OK' if r['eligible'] else ''), r['model'], r['quality'], r['fail_rate'] * 100,
            r['fidelity'], r['cost_per_1000'] or 0, r['secs'] or 0, r['think']))
    json.dump(rows, open(results_path('report.json'), 'w'), indent=1)
    win = [r for r in rows if r['eligible']]
    if win:
        out = {'generated': time.strftime('%Y-%m-%d'), 'source': 'tools/model-benchmark/benchmark.py',
               PROVIDER: {'default': win[0]['model'], 'fallbacks': [r['model'] for r in win[1:4]]}}
        json.dump(out, open(results_path('default-models.json'), 'w'), indent=1)
        print('\nproposal written to', results_path('default-models.json'))


args_global = None


def main():
    global args_global, OUT, PROVIDER, BASE, KEY_FILE
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['candidates', 'screen', 'full', 'report'])
    ap.add_argument('--provider', choices=list(PROVIDERS), default='openrouter')
    ap.add_argument('--budget', type=float, default=1.0, help='stop when total spent (USD) reaches this')
    ap.add_argument('--workers', type=int, default=6)
    ap.add_argument('--max-out-price', type=float, default=3.0, help='USD per million output tokens')
    ap.add_argument('--max-in-price', type=float, default=1.0, help='USD per million input tokens')
    ap.add_argument('--only', default='', help='regex: only screen matching model ids')
    ap.add_argument('--band', type=float, default=8.0, help='screen: keep models within this many points of the best')
    ap.add_argument('--top', type=int, default=40, help='full: max finalists')
    ap.add_argument('--margin', type=float, default=1.5, help='report: models this far below the best stay eligible as reserves')
    ap.add_argument('--tie', type=float, default=0.5, help='report: models this close to the best count as tied; cheapest of those wins')
    ap.add_argument('--flores', default=FLORES)
    ap.add_argument('--out', default=OUT)
    args = ap.parse_args()
    args_global = args
    PROVIDER = args.provider
    BASE = PROVIDERS[PROVIDER]['base']
    KEY_FILE = PROVIDERS[PROVIDER]['key_file']
    # OpenRouter keeps the original folder; other providers get their own, so results never mix.
    OUT = args.out if PROVIDER == 'openrouter' else os.path.join(args.out, PROVIDER)
    {'candidates': cmd_candidates, 'screen': cmd_screen, 'full': cmd_full, 'report': cmd_report}[args.cmd](args)


if __name__ == '__main__':
    main()
