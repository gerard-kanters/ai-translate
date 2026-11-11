#!/usr/bin/env python3
"""
Second benchmark round for the shortlisted models, with a better test for Chinese, Japanese and Korean.

Why: chrF++ compares with ONE reference translation. In languages without spaces a good translation that
picks other words scores ~30 even when it is native-quality. So for zh/ja/ko the quality is judged by
three LLM judges (different families) that see the source, the reference and the candidate, are told that
valid alternatives must not be penalised, and score accuracy and fluency from 1 to 5. chrF++ stays for
the other 22 languages. The sample is new (48 sentences per language instead of 24).

  python rerun.py controls     validates the judges on known good/bad texts (cheap)
  python rerun.py translate    translates the new sample with every shortlisted model
  python rerun.py judge        judges the zh/ja/ko translations
  python rerun.py report       prints and stores the results

Results and cache: /root/benchmark-out/rerun (everything resumes).
"""
import argparse
import json
import os
import random
import statistics
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from types import SimpleNamespace

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import benchmark as b  # noqa: E402

b.args_global = SimpleNamespace(flores=b.FLORES)

DATA = '/root/benchmark-out'
OUT = os.path.join(DATA, 'rerun')
SEED, N_BATCH, PER = 43, 6, 8
SOURCES = ['eng_Latn', 'nld_Latn', 'deu_Latn']
CJK = ['zho_Hans', 'jpn_Jpan', 'kor_Hang']
JUDGES = ['anthropic/claude-sonnet-5', 'openai/gpt-6-sol', 'qwen/qwen3.7-max']
MODELS = [
    ('openrouter', 'google/gemini-3.1-flash-lite'),
    ('openrouter', 'deepseek/deepseek-v4.1-flash'),
    ('openrouter', 'bytedance-seed/seed-2.0-mini'),
    ('openrouter', 'openai/gpt-6-luna'),
    ('openrouter', 'openai/gpt-5.6-luna'),
    ('openrouter', 'openai/gpt-4.1-mini'),
    ('openrouter', 'anthropic/claude-haiku-5.5'),
    ('deepseek', 'deepseek-flash'),
    ('deepseek', 'deepseek-v4-pro'),
    ('groq', 'openai/gpt-oss-120b'),
    ('groq', 'qwen/qwen3.8-27b'),
    ('groq', 'openai/gpt-oss-20b'),
]
CONC = {'openrouter': 8, 'deepseek': 6, 'groq': 3}
OR_BASE = b.PROVIDERS['openrouter']['base']
_lock = threading.Lock()
_cache = {}
SEMS = {p: threading.Semaphore(n) for p, n in CONC.items()}

JUDGE_SYSTEM = (
    'You are a senior professional translator and linguist and a native speaker of {t}. You review translations '
    'from {s} into {t} for a website translation product.\n\n'
    'For every item you receive the source text, ONE human reference translation and a candidate translation. '
    'The reference is only one acceptable translation: valid synonyms, a different sentence structure, other '
    'correct word choices and natural paraphrases must NOT be penalised. Judge the candidate on its own merits '
    'against the source.\n\n'
    'Score the candidate with integers from 1 to 5 on two scales.\n'
    'accuracy: 5 = meaning fully preserved, nothing added or omitted; 4 = minor nuance shifts; 3 = a noticeable '
    'meaning shift or a small omission or addition; 2 = major errors; 1 = wrong, unrelated or mostly missing.\n'
    'fluency: 5 = natural and idiomatic as written by an educated native speaker, with correct script, orthography '
    'and register; 4 = small awkwardness; 3 = understandable but clearly translated-sounding or inconsistent '
    'register; 2 = ungrammatical or unnatural; 1 = broken, wrong script or not in {t}.\n'
    'flag: "" or one of "wrong_language", "wrong_script", "untranslated", "truncated", "extra_text".\n\n'
    'Return ONLY valid JSON: {{"scores": {{"<id>": {{"accuracy": n, "fluency": n, "flag": ""}}}}}}. '
    'Every id must be scored. No other text.'
)


# ---------------------------------------------------------------- plumbing

def api_key(provider):
    cfg = b.PROVIDERS[provider]
    k = os.environ.get(cfg['env'], '').strip()
    if not k and os.path.exists(cfg['key_file']):
        k = open(cfg['key_file']).read().strip()
    if not k:
        sys.exit('No key for ' + provider)
    return k


def load_cache():
    p = os.path.join(OUT, 'calls.jsonl')
    if os.path.exists(p):
        for line in open(p, encoding='utf-8'):
            try:
                r = json.loads(line)
                _cache[r['key']] = r
            except ValueError:
                pass


def save(rec):
    with _lock:
        _cache[rec['key']] = rec
        with open(os.path.join(OUT, 'calls.jsonl'), 'a', encoding='utf-8') as f:
            f.write(json.dumps(rec, ensure_ascii=False) + '\n')


def spent():
    return sum(r.get('cost') or 0 for r in list(_cache.values()))


def screened_param(provider, model):
    base = DATA if provider == 'openrouter' else os.path.join(DATA, provider)
    for r in json.load(open(os.path.join(base, 'screen.json'))):
        if r['model'] == model and r.get('ok'):
            return r.get('param') or {}
    return {}


def sample_ids():
    return random.Random(SEED).sample(range(len(b.lines('eng_Latn'))), PER * N_BATCH)


def source_for(tgt, k):
    src = SOURCES[k % 3]
    return src if src != tgt else SOURCES[(k + 1) % 3]


def src_name(code):
    return b.SOURCES.get(code) or b.LANGS[code][1]


# ---------------------------------------------------------------- translate

def translate_jobs():
    ids = sample_ids()
    jobs = []
    for prov, model in MODELS:
        extra = screened_param(prov, model)
        for tgt in b.LANGS:
            for k in range(N_BATCH):
                src = source_for(tgt, k)
                chunk = ids[k * PER:(k + 1) * PER]
                jobs.append(dict(key='T|%s|%s|%s>%s|%d' % (prov, model, src, tgt, k), kind='tr', prov=prov, model=model,
                                 src=src, tgt=tgt, batch=k, extra=extra,
                                 segs=[('s%d' % i, b.lines(src)[i]) for i in chunk],
                                 refs={'s%d' % i: b.lines(tgt)[i] for i in chunk}))
        for tgt in ('deu_Latn', 'jpn_Jpan'):
            jobs.append(dict(key='F|%s|%s|%s' % (prov, model, tgt), kind='fid', prov=prov, model=model, src='eng_Latn',
                             tgt=tgt, batch=0, extra=extra, segs=[('f%d' % i, t) for i, t in enumerate(b.FIDELITY)], refs={}))
    return jobs


def run_translate(job, budget):
    if job['key'] in _cache:
        return 0
    if spent() >= budget:
        return 1
    with SEMS[job['prov']]:
        rec = b.call(api_key(job['prov']), job['model'], src_name(job['src']), b.LANGS[job['tgt']][1], job['segs'],
                     job['extra'], provider=job['prov'])
    rec.update(key=job['key'], kind=job['kind'], prov=job['prov'], model=job['model'], src=job['src'], tgt=job['tgt'],
               batch=job['batch'], refs=job['refs'], segs=[s[0] for s in job['segs']],
               src_texts={s[0]: s[1] for s in job['segs']})
    save(rec)
    return 0


def cmd_translate(args):
    jobs = translate_jobs()
    print('%d translation calls, %d cached, spent $%.2f, budget $%.2f' % (
        len(jobs), sum(1 for j in jobs if j['key'] in _cache), spent(), args.budget), flush=True)
    with ThreadPoolExecutor(args.workers) as ex:
        skipped = sum(ex.map(lambda j: run_translate(j, args.budget), jobs))
    print('translate done, skipped for budget: %d, spent $%.2f' % (skipped, spent()))


# ---------------------------------------------------------------- judging

def judge_call(judge, tgt, src, items):
    body = {'model': judge, 'temperature': 0, 'max_tokens': 8000, 'usage': {'include': True},
            'reasoning': {'effort': 'low'},
            'messages': [{'role': 'system', 'content': JUDGE_SYSTEM.format(s=src_name(src), t=b.LANGS[tgt][1])},
                         {'role': 'user', 'content': json.dumps({'items': items}, ensure_ascii=False)}]}
    status, data = 0, {}
    t0 = time.time()
    for attempt in range(4):
        status, data = b.http('/chat/completions', body, api_key('openrouter'), base=OR_BASE, timeout=240)
        err = json.dumps(data.get('error', ''))[:400].lower()
        if status == 400 and ('reasoning' in err or 'temperature' in err):
            body.pop('reasoning', None)
            if 'temperature' in err:
                body.pop('temperature', None)
            continue
        if status in (0, 429, 500, 502, 503, 504) and attempt < 3:
            time.sleep(3 + attempt * 4)
            continue
        break
    rec = {'status': status, 'secs': round(time.time() - t0, 1)}
    if status != 200:
        rec['error'] = json.dumps(data.get('error', ''))[:300]
        return rec
    msg = (data.get('choices') or [{}])[0].get('message') or {}
    text = (msg.get('content') or '').replace('```json', '').replace('```', '')
    a, z = text.find('{'), text.rfind('}')
    scores = None
    if a >= 0 and z >= a:
        try:
            scores = json.loads(text[a:z + 1]).get('scores')
        except ValueError:
            scores = None
    rec['scores'] = scores if isinstance(scores, dict) else None
    rec['cost'] = (data.get('usage') or {}).get('cost') or 0
    return rec


def run_judge(key, judge, tgt, src, items, budget, meta):
    if key in _cache:
        return 0
    if spent() >= budget:
        return 1
    rec = judge_call(judge, tgt, src, items)
    rec.update(key=key, kind='judge', judge=judge, tgt=tgt, **meta)
    save(rec)
    return 0


def hyp_map(rec):
    t = b.parse(rec.get('content') or '') if rec.get('status') == 200 else None
    return {sid: (t[sid].strip() if isinstance((t or {}).get(sid), str) else '') for sid in rec['segs']}


def judge_jobs():
    jobs = []
    for rec in list(_cache.values()):
        if rec.get('kind') != 'tr' or rec['tgt'] not in CJK:
            continue
        hyps = hyp_map(rec)
        items = [{'id': sid, 'source': rec['src_texts'][sid], 'reference': rec['refs'][sid], 'candidate': hyps[sid]}
                 for sid in rec['segs'] if hyps[sid]]
        if not items:
            continue
        for judge in JUDGES:
            jobs.append((('J|%s|%s' % (judge, rec['key'])), judge, rec['tgt'], rec['src'], items,
                         {'model': rec['model'], 'prov': rec['prov'], 'batch': rec['batch']}))
    return jobs


def cmd_judge(args):
    load_cache()
    jobs = judge_jobs()
    print('%d judge calls, %d cached, spent $%.2f, budget $%.2f' % (
        len(jobs), sum(1 for j in jobs if j[0] in _cache), spent(), args.budget), flush=True)
    with ThreadPoolExecutor(args.workers) as ex:
        skipped = sum(ex.map(lambda j: run_judge(j[0], j[1], j[2], j[3], j[4], args.budget, j[5]), jobs))
    print('judge done, skipped for budget: %d, spent $%.2f' % (skipped, spent()))


# ---------------------------------------------------------------- controls

def control_variants():
    import opencc
    s2t = opencc.OpenCC('s2t')
    ids = sample_ids()
    out = []
    for tgt in CJK:
        for k in (0, 1):
            chunk = ids[k * PER:(k + 1) * PER]
            src = source_for(tgt, k)
            refs = [b.lines(tgt)[i] for i in chunk]
            variants = {
                'reference': refs,
                'shuffled': refs[1:] + refs[:1],
                'truncated': [r[:max(1, len(r) // 2)] for r in refs],
                'wrong_language': [s2t.convert(r) for r in refs] if tgt == 'zho_Hans' else [b.lines('zho_Hans')[i] for i in chunk],
            }
            for name, cands in variants.items():
                items = [{'id': 's%d' % i, 'source': b.lines(src)[i], 'reference': r, 'candidate': c}
                         for i, r, c in zip(chunk, refs, cands)]
                out.append((name, tgt, k, src, items))
    return out


def cmd_controls(args):
    load_cache()
    jobs = []
    for name, tgt, k, src, items in control_variants():
        for judge in JUDGES:
            jobs.append(('C|%s|%s|%s|%d' % (judge, name, tgt, k), judge, tgt, src, items, {'control': name, 'batch': k}))
    print('%d control calls, spent $%.2f' % (len(jobs), spent()), flush=True)
    with ThreadPoolExecutor(args.workers) as ex:
        list(ex.map(lambda j: run_judge(j[0], j[1], j[2], j[3], j[4], args.budget, j[5]), jobs))
    print('controls done, spent $%.2f' % spent())
    print_controls()


CAPPED_FLAGS = {'wrong_language', 'wrong_script', 'untranslated'}


def sent_score(s):
    """Accuracy (70%) and fluency (30%), 1-5 each, to one 0-100 number. A fluent text with the wrong meaning
    must score low, and text in the wrong language or script is capped at 20. None when unusable."""
    try:
        a, f = float(s['accuracy']), float(s['fluency'])
    except (KeyError, TypeError, ValueError):
        return None
    v = (0.7 * a + 0.3 * f - 1) * 25
    if (s.get('flag') or '') in CAPPED_FLAGS:
        v = min(v, 20.0)
    return v


def print_controls():
    print('\nJudge controls: mean score 0-100 (a good judge gives the reference ~100 and the others low)')
    print('%-16s' % 'control' + ''.join('%-30s' % j.split('/')[-1] for j in JUDGES))
    for ctrl in ('reference', 'shuffled', 'truncated', 'wrong_language'):
        row = '%-16s' % ctrl
        for j in JUDGES:
            vals = []
            for r in _cache.values():
                if r.get('kind') == 'judge' and r.get('control') == ctrl and r['judge'] == j and r.get('scores'):
                    vals += [v for v in (sent_score(s) for s in r['scores'].values()) if v is not None]
            row += '%-30s' % ('%.0f (n=%d)' % (statistics.mean(vals), len(vals)) if vals else 'no data')
        print(row)
    bad = [(r['judge'], r.get('control') or r['model'], r['tgt'], r.get('error')) for r in _cache.values()
           if r.get('kind') == 'judge' and r.get('status') != 200]
    if bad:
        print('failed judge calls:', bad[:5])


# ---------------------------------------------------------------- report

def spearman(x, y):
    def ranks(v):
        order = sorted(range(len(v)), key=lambda i: v[i])
        r = [0] * len(v)
        for pos, i in enumerate(order):
            r[i] = pos
        return r
    rx, ry = ranks(x), ranks(y)
    n = len(x)
    return 1 - 6 * sum((a - c) ** 2 for a, c in zip(rx, ry)) / (n * (n * n - 1))


def cmd_report(args):
    load_cache()
    tr = {}
    fid = {}
    for r in _cache.values():
        if r.get('kind') == 'tr':
            tr.setdefault((r['prov'], r['model']), []).append(r)
        elif r.get('kind') == 'fid':
            fid.setdefault((r['prov'], r['model']), []).append(r)
    # judge scores per (prov, model, tgt, sid) per judge
    js = {}
    for r in _cache.values():
        if r.get('kind') == 'judge' and r.get('scores') and 'model' in r:
            for sid, s in r['scores'].items():
                v = sent_score(s)
                if v is not None:
                    js.setdefault((r['prov'], r['model'], r['tgt'], r['batch'], sid), {})[r['judge']] = (v, s.get('flag') or '')
    rows = []
    for (prov, model), recs in tr.items():
        if len(recs) < len(b.LANGS) * N_BATCH:
            continue
        scores, fail, total = b.score_records(recs)
        wsum = sum(b.LANGS[t][2] for t in scores if t not in CJK)
        q22 = sum(b.LANGS[t][2] * s for t, s in scores.items() if t not in CJK) / wsum
        cjk = {}
        flags = {}
        per_judge = {j: [] for j in JUDGES}
        for t in CJK:
            vals = []
            for r in recs:
                if r['tgt'] != t:
                    continue
                hyps = hyp_map(r)
                for sid in r['segs']:
                    if not hyps[sid]:
                        vals.append(0.0)
                        continue
                    got = js.get((prov, model, t, r['batch'], sid), {})
                    if not got:
                        continue
                    vals.append(statistics.median(v for v, _ in got.values()))
                    for j, (v, fl) in got.items():
                        per_judge[j].append(v)
                        if fl:
                            flags[fl] = flags.get(fl, 0) + 1
            cjk[t] = statistics.mean(vals) if vals else None
        ok = [r for r in recs + fid.get((prov, model), []) if r.get('status') == 200]
        cost = sum(r.get('cost') or 0 for r in recs + fid.get((prov, model), []))
        rows.append(dict(prov=prov, model=model, q22=q22, chrf_cjk={CODE_OF[t]: scores[t] for t in CJK},
                         cjk={CODE_OF[t]: cjk[t] for t in CJK}, cjk_mean=statistics.mean([v for v in cjk.values() if v is not None]) if any(v is not None for v in cjk.values()) else None,
                         fail=fail / total if total else 1, fid=b.fidelity_rate(fid.get((prov, model), []), None),
                         cost_per_1000=cost / total * 1000 if total else None,
                         secs=statistics.median(r['secs'] for r in ok) if ok else None,
                         think=statistics.mean(r.get('think', 0) for r in ok) if ok else 0, flags=flags,
                         judge_means={j: (statistics.mean(v) if v else None) for j, v in per_judge.items()}))
    rows = [r for r in rows if r['cjk_mean'] is not None]
    if not rows:
        sys.exit('Nothing to report yet')

    def z(vals):
        m, sd = statistics.mean(vals), statistics.pstdev(vals) or 1
        return [(v - m) / sd for v in vals]
    z22, zc = z([r['q22'] for r in rows]), z([r['cjk_mean'] for r in rows])
    for r, a, c in zip(rows, z22, zc):
        r['index'] = 0.85 * a + 0.15 * c  # CJK is 6 of 40 language weights
    rows.sort(key=lambda r: -r['index'])
    print('Round 2: new sample of 48 sentences per language. q22 = chrF++ over the 22 other languages; CJK = judge score 0-100')
    print('%-10s %-32s %5s | %4s %4s %4s %5s | %5s %5s %6s %5s %5s %6s' % (
        'provider', 'model', 'q22', 'zh', 'ja', 'ko', 'CJK', 'fail%', 'fid', '$/1000', 'secs', 'think', 'index'))
    for r in rows:
        print('%-10s %-32s %5.1f | %4.0f %4.0f %4.0f %5.0f | %5.1f %5.2f %6.3f %5.1f %5.0f %+6.2f' % (
            r['prov'], r['model'], r['q22'], r['cjk']['zh'] or 0, r['cjk']['ja'] or 0, r['cjk']['ko'] or 0, r['cjk_mean'],
            r['fail'] * 100, r['fid'], r['cost_per_1000'] or 0, r['secs'] or 0, r['think'], r['index']))
    print('\nCJK judge means per judge (a judge scoring its own family high is a bias signal):')
    for r in rows:
        print('  %-32s ' % r['model'] + '  '.join('%s %.0f' % (j.split('/')[-1], v or 0) for j, v in r['judge_means'].items())
              + ('   flags: %s' % r['flags'] if r['flags'] else ''))
    # judge agreement: Spearman on (model, language) means
    pts = {j: [] for j in JUDGES}
    for r in rows:
        for t in CJK:
            for j in JUDGES:
                vals = [v[j][0] for k, v in js.items() if k[0] == r['prov'] and k[1] == r['model'] and k[2] == t and j in v]
                pts[j].append(statistics.mean(vals) if vals else 0)
    print('\nJudge agreement (Spearman over model x language means):')
    for i, a in enumerate(JUDGES):
        for c in JUDGES[i + 1:]:
            print('  %s vs %s: %.2f' % (a.split('/')[-1], c.split('/')[-1], spearman(pts[a], pts[c])))
    json.dump(rows, open(os.path.join(OUT, 'report.json'), 'w'), indent=1)
    print('\nwritten', os.path.join(OUT, 'report.json'), '; spent $%.2f' % spent())
    print_controls()


CODE_OF = {f: v[0] for f, v in b.LANGS.items()}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['controls', 'translate', 'judge', 'report'])
    ap.add_argument('--budget', type=float, default=15.0, help='stop when the total spent in this folder reaches this (USD)')
    ap.add_argument('--workers', type=int, default=16)
    args = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    load_cache()
    {'controls': cmd_controls, 'translate': cmd_translate, 'judge': cmd_judge, 'report': cmd_report}[args.cmd](args)


if __name__ == '__main__':
    main()
