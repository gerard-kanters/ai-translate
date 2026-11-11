#!/usr/bin/env python3
"""
Builds the results workbook (resultaten-modelbenchmark.xlsx) from the benchmark reports.
Maintainer tool, not shipped. Run with the benchmark venv (needs xlsxwriter and opencc):

  /root/benchmark-venv/bin/python build_xlsx.py [data-dir]

data-dir defaults to /root/benchmark-out (round 1: OpenRouter there, other providers in a subfolder each;
round 2: the rerun subfolder).
"""
import json
import os
import statistics
import sys

import xlsxwriter
import xlsxwriter.utility

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import benchmark as b  # LANGS (FLORES code -> plugin code, name, weight)
import rerun  # judge scoring, cache of round 2

DATA = sys.argv[1] if len(sys.argv) > 1 else '/root/benchmark-out'
rerun.OUT = os.path.join(DATA, 'rerun')
OUT = os.path.join(HERE, 'resultaten-modelbenchmark.xlsx')
PROVIDERS = [('openrouter', 'OpenRouter'), ('deepseek', 'DeepSeek'), ('groq', 'Groq')]
LABEL = dict(PROVIDERS)
CODE = {f: v[0] for f, v in b.LANGS.items()}
GATE_FID, GATE_THINK, MARGIN, TIE = 0.8, 20, 1.5, 0.5

NOTES = {
    ('groq', 'openai/gpt-oss-120b'): 'Denkt ~25 tokens per aanroep en dat kan op Groq niet uit; bij 0,9 s per aanroep geen probleem.',
    ('groq', 'openai/gpt-oss-20b'): 'Valt in 1,3% van de segmenten uit (ronde 2: 8%).',
    ('groq', 'qwen/qwen3.6-27b'): 'Prijs niet vermeld door Groq: aangenomen gelijk aan qwen3.8-27b. Opmaak 0% betekent dat markup niet behouden bleef.',
    ('groq', 'qwen/qwen3.8-27b'): '6x duurder dan gpt-oss-120b en valt in 2% van de segmenten uit.',
    ('openrouter', 'amazon/nova-lite-v1'): 'Verloor een Hebreeuws batchantwoord: aanhalingstekens in het Hebreeuws braken de JSON.',
    ('openrouter', 'deepseek/deepseek-v4-flash'): 'Denkprobe in de eerste run miste het denken (gemiddeld 968 tokens, 8% uitval).',
    ('openrouter', 'openai/gpt-6-luna'): 'Huidige OpenRouter-default van de plugin. In ronde 1 leek het 2,5 punt lager, maar dat kwam grotendeels door de CJK-meting (zie CJK-test).',
    ('openrouter', 'google/gemini-3.1-flash-lite'): 'Beste kwaliteit, snelst, denkt niet (geen parameter nodig).',
    ('openrouter', 'deepseek/deepseek-v4.1-flash-1017'): 'Vastgepinde snapshot: kan verdwijnen.',
    ('deepseek', 'deepseek-flash'): 'Is DeepSeek-V4.1-Flash-1017. Prijs op piektarief; daluren de helft.',
    ('deepseek', 'deepseek-v4-pro'): 'Prijs op piektarief; daluren de helft.',
}

# ------------------------------------------------------------------ round 1 data
rows = []
screen = []
for key, label in PROVIDERS:
    base = DATA if key == 'openrouter' else os.path.join(DATA, key)
    rep = json.load(open(os.path.join(base, 'report.json')))
    scr = json.load(open(os.path.join(base, 'screen.json')))
    gated = [r for r in rep if r['fail_rate'] == 0 and r['fidelity'] >= GATE_FID and r['think'] <= GATE_THINK]
    best = max((r['quality'] for r in gated), default=None)
    for r in sorted(rep, key=lambda r: -r['quality']):
        reasons = []
        if r['fail_rate'] > 0:
            reasons.append('uitval')
        if r['think'] > GATE_THINK:
            reasons.append('denkt')
        if r['fidelity'] < GATE_FID:
            reasons.append('opmaak')
        if reasons:
            status = 'Afgewezen: ' + ', '.join(reasons)
        elif r['quality'] >= best - TIE:
            status = 'Gelijk aan beste'
        elif r['quality'] >= best - MARGIN:
            status = 'Reserve'
        else:
            status = 'Te laag'
        rows.append(dict(key=key, label=label, model=r['model'], q=r['quality'], fail=r['fail_rate'], fid=r['fidelity'],
                         cost=r['cost_per_1000'], secs=r['secs'], think=r['think'], status=status,
                         weak=', '.join(CODE.get(t, t) for t in r['weak_langs']), per=r['per_lang'],
                         note=NOTES.get((key, r['model']), '')))
    for s in scr:
        screen.append(dict(label=label, **s))

# ------------------------------------------------------------------ round 2 data
r2 = json.load(open(os.path.join(DATA, 'rerun', 'report.json')))
rerun.load_cache()
R2_NOTES = {
    ('openrouter', 'google/gemini-3.1-flash-lite'): 'Beste in beide rondes, snelst, denkt niet.',
    ('openrouter', 'openai/gpt-6-luna'): 'CJK-oordeel gelijk aan gpt-5.6-luna, maar 0,7 punt lager op de 22 talen en minder dan de helft van de prijs; 2,1 punt onder gemini.',
    ('groq', 'qwen/qwen3.8-27b'): 'Vertaalt ~19% van de Japanse zinnen naar het Chinees (9 van 48). Niet aanbevolen.',
    ('groq', 'openai/gpt-oss-120b'): 'Denkt gemiddeld ~70 tokens (kan niet uit), maar is razendsnel. Verloor één Hebreeuws batchantwoord.',
    ('groq', 'openai/gpt-oss-20b'): 'Verliest hele batches in meerdere talen (8% uitval).',
    ('openrouter', 'openai/gpt-4.1-mini'): 'Verloor één Hebreeuws batchantwoord (aanhalingstekens breken de JSON); zwakste Chinees van de groep.',
    ('deepseek', 'deepseek-flash'): 'Eén ontbrekend segment in 2 batches: ruis.',
    ('deepseek', 'deepseek-v4-pro'): 'Ronde 2 +1,0 punt op de 22 talen, ronde 1 gelijk; 3,7x duurder en 2x langzamer.',
}

# controls and judge agreement from the cache
CONTROLS = ('reference', 'shuffled', 'truncated', 'wrong_language')
ctl = {}
for c in CONTROLS:
    for j in rerun.JUDGES:
        vals = []
        for r in rerun._cache.values():
            if r.get('kind') == 'judge' and r.get('control') == c and r['judge'] == j and r.get('scores'):
                vals += [v for v in (rerun.sent_score(s) for s in r['scores'].values()) if v is not None]
        ctl[(c, j)] = (statistics.mean(vals) if vals else 0, len(vals))
means = {j: [] for j in rerun.JUDGES}
for row in r2:
    for t in rerun.CJK:
        for j in rerun.JUDGES:
            vals = []
            for r in rerun._cache.values():
                if r.get('kind') == 'judge' and r.get('judge') == j and r.get('prov') == row['prov'] and r.get('model') == row['model'] and r['tgt'] == t and r.get('scores'):
                    vals += [v for v in (rerun.sent_score(s) for s in r['scores'].values()) if v is not None]
            means[j].append(statistics.mean(vals) if vals else 0)
agree = [(a, c, rerun.spearman(means[a], means[c])) for i, a in enumerate(rerun.JUDGES) for c in rerun.JUDGES[i + 1:]]

wb = xlsxwriter.Workbook(OUT)
wa = wb.add_worksheet('Advies')
w2 = wb.add_worksheet('Ronde 2')
wc = wb.add_worksheet('CJK-test')
ws = wb.add_worksheet('Ronde 1')
wl = wb.add_worksheet('Per taal (ronde 1)')
wsn = wb.add_worksheet('Screening')
wu = wb.add_worksheet('Uitleg')


def F(**k):
    return wb.add_format(dict({'font_name': 'Arial', 'font_size': 10, 'valign': 'vcenter'}, **k))


f_title = F(bold=True, font_size=14)
f_h2 = F(bold=True, font_size=11)
f_sub = F(italic=True, font_color='#555555', text_wrap=True, valign='top')
f_head = F(bold=True, bg_color='#1F3864', font_color='#FFFFFF', text_wrap=True, align='center', border=1)
f_text = F()
f_wrap = F(text_wrap=True, valign='top')
f_bold = F(bold=True)
f_score = F(num_format='0.0')
f_cost2 = F(num_format='$0.00')
f_delta = F(num_format='+0.0;-0.0;0.0')
f_idx = F(num_format='+0.00;-0.00;0.00')
f_in = F(font_color='#0000FF', num_format='0.0')          # measured inputs: blue
f_in0 = F(font_color='#0000FF', num_format='0')
f_in_pct = F(font_color='#0000FF', num_format='0.0%')
f_in_pct0 = F(font_color='#0000FF', num_format='0%')
f_in_cost = F(font_color='#0000FF', num_format='$0.0000')
f_in_int = F(font_color='#0000FF', num_format='0')
f_in_price = F(font_color='#0000FF', num_format='0.000')
f_in_2 = F(font_color='#0000FF', num_format='0.00')

# ------------------------------------------------------------------ Ronde 2
n2 = len(r2)
last2 = n2 + 1
h2 = ['Provider', 'Model', 'Kwaliteit 22 talen (chrF++)', 'CJK-oordeel zh (0-100)', 'CJK-oordeel ja', 'CJK-oordeel ko',
      'CJK-oordeel gemiddeld', 'Uitval (% segmenten)', 'Opmaak behouden', 'Kosten per 1000 zinnen ($)',
      'Snelheid (s per aanroep)', 'Denktokens per aanroep', 'Index (85% 22 talen, 15% CJK; z-score)',
      'Rechter Claude (CJK)', 'Rechter GPT (CJK)', 'Rechter Qwen (CJK)', 'Vlaggen van rechters',
      'Ter vergelijking: chrF++ zh (oude methode)', 'chrF++ ja (oude methode)', 'chrF++ ko (oude methode)', 'Opmerking', 'Sleutel']
for c, h in enumerate(h2):
    w2.write(0, c, h, f_head)
w2.set_row(0, 62)
q22s = [r['q22'] for r in r2]
cjks = [r['cjk_mean'] for r in r2]
m22, s22 = statistics.mean(q22s), statistics.pstdev(q22s)
mc, sc = statistics.mean(cjks), statistics.pstdev(cjks)
for i, r in enumerate(r2):
    x = i + 1
    e = x + 1
    w2.write(x, 0, LABEL[r['prov']], f_text)
    w2.write(x, 1, r['model'], f_text)
    w2.write_number(x, 2, r['q22'], f_in)
    for c, code in enumerate(('zh', 'ja', 'ko')):
        w2.write_number(x, 3 + c, r['cjk'][code], f_in0)
    w2.write_formula(x, 6, '=AVERAGE(D%d:F%d)' % (e, e), f_score, r['cjk_mean'])
    w2.write_number(x, 7, r['fail'], f_in_pct)
    w2.write_number(x, 8, r['fid'], f_in_pct0)
    w2.write_number(x, 9, r['cost_per_1000'], f_in_cost)
    w2.write_number(x, 10, r['secs'], f_in)
    w2.write_number(x, 11, r['think'], f_in_int)
    idx = 0.85 * (r['q22'] - m22) / s22 + 0.15 * (r['cjk_mean'] - mc) / sc
    w2.write_formula(x, 12, '=0.85*(C%d-AVERAGE($C$2:$C$%d))/STDEVP($C$2:$C$%d)+0.15*(G%d-AVERAGE($G$2:$G$%d))/STDEVP($G$2:$G$%d)' % (
        e, last2, last2, e, last2, last2), f_idx, idx)
    for c, j in enumerate(rerun.JUDGES):
        w2.write_number(x, 13 + c, r['judge_means'][j] or 0, f_in0)
    w2.write(x, 16, ', '.join('%s %d' % (k, v) for k, v in r['flags'].items()), f_text)
    for c, code in enumerate(('zh', 'ja', 'ko')):
        w2.write_number(x, 17 + c, r['chrf_cjk'][code], f_in)
    w2.write(x, 20, R2_NOTES.get((r['prov'], r['model']), ''), f_text)
    w2.write_formula(x, 21, '=LOWER(A%d)&"|"&B%d' % (e, e), f_text, r['prov'] + '|' + r['model'])
w2.freeze_panes(1, 2)
w2.autofilter(0, 0, n2, 21)
w2.set_column(0, 0, 12)
w2.set_column(1, 1, 34)
w2.set_column(2, 19, 13)
w2.set_column(16, 16, 30)
w2.set_column(20, 20, 70)
w2.set_column(21, 21, 44)
w2.conditional_format(1, 12, n2, 12, {'type': 'data_bar', 'bar_color': '#9DC3E6', 'bar_solid': True})
w2.write(n2 + 2, 0, 'Nieuwe steekproef van 48 zinnen per taal (ronde 1: 24 andere zinnen). De 22 talen buiten zh/ja/ko zijn gemeten met chrF++. Chinees, Japans en Koreaans zijn beoordeeld door drie LLM-rechters (mediaan per zin), zie het tabblad CJK-test. De oude chrF++-scores voor zh/ja/ko staan rechts ter vergelijking.', f_sub)
w2.write(n2 + 3, 0, 'Blauw = gemeten waarde (invoer); zwart = formule. Index: z-score over deze 12 modellen, dus alleen binnen deze lijst vergelijkbaar.', f_sub)

# ------------------------------------------------------------------ CJK-test
wc.set_column(0, 0, 34)
wc.set_column(1, 4, 24)
wc.set_column(5, 5, 60)
wc.write(0, 0, 'Een betere test voor Chinees, Japans en Koreaans', f_title)
txt = [
    'Probleem: chrF++ vergelijkt met één referentievertaling en telt tekenreeksen. Zonder spaties kost elk ander woord veel punten, ook bij een native-goede vertaling. Daardoor scoorde DeepSeek in het Chinees ~30. Andere n-grammetrieken (spBLEU, char-BLEU, chrF met korte n-grammen) rangschikken de modellen vrijwel gelijk (correlatie 0,7-0,97) en hebben hetzelfde lage niveau.',
    'Oplossing: drie LLM-rechters uit verschillende families (claude-sonnet-5, gpt-6-sol, qwen3.7-max) krijgen bron, referentie en vertaling, met de opdracht goede alternatieven niet te bestraffen. Ze scoren juistheid en vloeiendheid van 1 tot 5. Score = 70% juistheid + 30% vloeiendheid, omgerekend naar 0-100; een vlag voor verkeerde taal, verkeerd schrift of onvertaald begrenst de score op 20. Per zin geldt de mediaan van de drie rechters.',
]
for i, t in enumerate(txt):
    wc.merge_range(2 + i, 0, 2 + i, 5, t, f_wrap)
    wc.set_row(2 + i, 58)
wc.write(5, 0, 'Validatie van de rechters (gemiddelde score 0-100)', f_h2)
head = ['Controle', 'Claude Sonnet 5', 'GPT-6 Sol', 'Qwen 3.7 Max', 'Verwacht']
for c, h in enumerate(head):
    wc.write(6, c, h, f_head)
names = {'reference': ('De menselijke referentie zelf', 'hoog (~100)'), 'shuffled': ('Vloeiende zin met de verkeerde betekenis', 'laag'),
         'truncated': ('Halve vertaling (afgekapt)', 'laag'), 'wrong_language': ('Verkeerde taal of schrift', 'laag (<= 20)')}
for i, c in enumerate(CONTROLS):
    wc.write(7 + i, 0, names[c][0], f_text)
    for k, j in enumerate(rerun.JUDGES):
        wc.write_number(7 + i, 1 + k, ctl[(c, j)][0], f_in0)
    wc.write(7 + i, 4, names[c][1], f_text)
wc.write(12, 0, 'Overeenkomst tussen rechters (Spearman, 12 modellen x 3 talen)', f_h2)
for c, h in enumerate(['Paar', 'Correlatie']):
    wc.write(13, c, h, f_head)
for i, (a, c, v) in enumerate(agree):
    wc.write(14 + i, 0, '%s - %s' % (a.split('/')[-1], c.split('/')[-1]), f_text)
    wc.write_number(14 + i, 1, v, f_in_2)
wc.write(18, 0, 'Wat de nieuwe test liet zien', f_h2)
finds = [
    'De CJK-scores zijn nu geloofwaardig: de meeste modellen halen 92-98 in plaats van ~30. Chinees, Japans en Koreaans onderscheiden de top nauwelijks, de verschillen zitten vooral in de 22 andere talen.',
    'qwen3.8-27b op Groq vertaalt 9 van 48 Japanse zinnen naar het Chinees (door de rechters gevlagd als verkeerde taal). Met chrF was alleen een lage score te zien.',
    'gpt-6-luna scoort in CJK even goed als gpt-5.6-luna (97). Het verschil in ronde 1 was grotendeels een meetfout.',
    'gpt-oss-20b verliest hele batches in meerdere talen (8% uitval) en valt af.',
    'Hebreeuws breekt de JSON bij drie modellen (nova-lite, gpt-4.1-mini, gpt-oss-120b): aanhalingstekens in Hebreeuwse afkortingen zoals מ"מ. De plugin kan dit zelf afvangen met tolerant parsen.',
    'Ruis: met 48 zinnen per taal is het verschil tussen twee modellen op de 22 talen pas echt boven ~0,6 punt.',
]
for i, t in enumerate(finds):
    wc.merge_range(19 + i, 0, 19 + i, 5, '- ' + t, f_wrap)
    wc.set_row(19 + i, 30)

# ------------------------------------------------------------------ Ronde 1
heads = ['Provider', 'Model', 'Kwaliteit (chrF++, alle 25 talen)', 'Uitval (% segmenten)', 'Opmaak behouden', 'Kosten per 1000 zinnen ($)',
         'Snelheid (s per aanroep)', 'Denktokens per aanroep', 'Verschil met beste van provider (punten)',
         'Kosten per 100.000 zinnen ($)', 'Status', 'Zwakste talen', 'Opmerking', 'Sleutel',
         'Kwaliteit zonder zh/ja/ko (22 talen)']
for c, h in enumerate(heads):
    ws.write(0, c, h, f_head)
ws.set_row(0, 48)
n = len(rows)
last = n + 1
best_by = {}
for r in rows:
    best_by[r['key']] = max(best_by.get(r['key'], 0), r['q'])
codes = [v[0] for v in b.LANGS.values()]
weights = [v[2] for v in b.LANGS.values()]
ci = {c: i for i, c in enumerate(codes)}
cjk_idx = [ci['zh'], ci['ja'], ci['ko']]
for i, r in enumerate(rows):
    x = i + 1
    e = x + 1
    ws.write(x, 0, r['label'], f_text)
    ws.write(x, 1, r['model'], f_text)
    ws.write_number(x, 2, r['q'], f_in)
    ws.write_number(x, 3, r['fail'], f_in_pct)
    ws.write_number(x, 4, r['fid'], f_in_pct0)
    ws.write_number(x, 5, r['cost'], f_in_cost)
    ws.write_number(x, 6, r['secs'], f_in)
    ws.write_number(x, 7, r['think'], f_in_int)
    ws.write_formula(x, 8, '=C%d-_xlfn.MAXIFS($C$2:$C$%d,$A$2:$A$%d,A%d)' % (e, last, last, e), f_delta, r['q'] - best_by[r['key']])
    ws.write_formula(x, 9, '=F%d*100' % e, f_cost2, r['cost'] * 100)
    ws.write(x, 10, r['status'], f_text)
    ws.write(x, 11, r['weak'], f_text)
    ws.write(x, 12, r['note'], f_text)
    ws.write_formula(x, 13, '=LOWER(A%d)&"|"&B%d' % (e, e), f_text, r['key'] + '|' + r['model'])
    num = sum(r['per'][c] * w for c, w in zip(codes, weights)) - sum(r['per'][codes[k]] * weights[k] for k in cjk_idx)
    den = sum(weights) - sum(weights[k] for k in cjk_idx)
    ws.write_formula(x, 14, "='Per taal (ronde 1)'!AC%d" % (x + 2), f_score, num / den)
ws.freeze_panes(1, 2)
ws.autofilter(0, 0, n, 14)
ws.set_column(0, 0, 12)
ws.set_column(1, 1, 38)
ws.set_column(2, 9, 14)
ws.set_column(10, 10, 24)
ws.set_column(11, 11, 16)
ws.set_column(12, 12, 70)
ws.set_column(13, 13, 44)
ws.set_column(14, 14, 16)
ws.conditional_format(1, 2, n, 2, {'type': 'data_bar', 'bar_color': '#9DC3E6', 'bar_solid': True})
ws.conditional_format(1, 10, n, 10, {'type': 'text', 'criteria': 'begins with', 'value': 'Afgewezen', 'format': F(font_color='#C00000')})
ws.conditional_format(1, 10, n, 10, {'type': 'text', 'criteria': 'begins with', 'value': 'Gelijk', 'format': F(font_color='#375623', bold=True)})
ws.write(n + 2, 0, 'Ronde 1: 24 zinnen per taal, alle 25 talen met chrF++. De scores voor zh/ja/ko zijn daar onbetrouwbaar (zie CJK-test); gebruik de laatste kolom of het tabblad Ronde 2.', f_sub)
ws.write(n + 3, 0, 'Blauw = gemeten waarde (invoer); zwart = formule. Kosten zijn de werkelijk verbruikte tokens maal de lijstprijs (OpenRouter: door de provider gemeld).', f_sub)

# ------------------------------------------------------------------ Advies
wa.write(0, 0, 'Aanbevolen standaardmodel per provider', f_title)
wa.merge_range(1, 0, 1, 12, 'Rang 1 is de standaard, de rest zijn reserves voor als de provider een model intrekt. De cijfers komen uit het tabblad Ronde 2 (formules). OpenAI, DeepInfra en Anthropic zijn niet rechtstreeks gemeten maar afgeleid uit de OpenRouter-meting van hetzelfde model.', f_sub)
wa.set_row(1, 36)
ah = ['Provider', 'Rang', 'Model-ID voor de plugin', 'Gemeten als (sleutel)', 'Basis', 'Kwaliteit 22 talen (chrF++)', 'CJK-oordeel (0-100)',
      'Uitval (% segmenten)', 'Snelheid (s per aanroep)', 'Kosten per 1000 zinnen ($)', 'Kosten per 100.000 zinnen ($)',
      'Verschil 22 talen met rang 1 (punten)', 'Opmerking']
for c, h in enumerate(ah):
    wa.write(3, c, h, f_head)
wa.set_row(3, 48)
D = 'rechtstreeks gemeten'
A = 'afgeleid uit OpenRouter'
advice = [
    ('OpenRouter', 1, 'google/gemini-3.1-flash-lite', 'openrouter|google/gemini-3.1-flash-lite', D, 'Beste in beide rondes, snelst, denkt niet, CJK-oordeel 98.'),
    ('OpenRouter', 2, 'bytedance-seed/seed-2.0-mini', 'openrouter|bytedance-seed/seed-2.0-mini', D, 'Binnen 0,5 punt van gemini en de goedkoopste van de kopgroep; Chinees model (privacy-afweging).'),
    ('OpenRouter', 3, 'deepseek/deepseek-v4.1-flash', 'openrouter|deepseek/deepseek-v4.1-flash', D, 'Stabiel ID, 0,9 punt onder gemini; reserve.'),
    ('DeepSeek', 1, 'deepseek-flash', 'deepseek|deepseek-flash', D, 'Plugin-default deepseek-v4-flash is een oude naam die hiernaar doorverwijst: default aanpassen.'),
    ('DeepSeek', 2, 'deepseek-v4-pro', 'deepseek|deepseek-v4-pro', D, 'Ronde 2 +1,0 punt, ronde 1 gelijk; 3,7x duurder en 2x langzamer.'),
    ('Groq', 1, 'openai/gpt-oss-120b', 'groq|openai/gpt-oss-120b', D, 'Enige bruikbare model op Groq; denkt ~70 tokens (kan niet uit) maar is razendsnel. Geen goede reserve.'),
    ('OpenAI', 1, 'gpt-6-luna', 'openrouter|openai/gpt-6-luna', A, 'Keuze op prijs. CJK-oordeel gelijk aan gpt-5.6-luna.'),
    ('OpenAI', 2, 'gpt-5.6-luna', 'openrouter|openai/gpt-5.6-luna', A, '0,7 punt beter op de 22 talen, 2,4x de prijs.'),
    ('OpenAI', 3, 'gpt-4.1-mini', 'openrouter|openai/gpt-4.1-mini', A, 'Even goed als gpt-5.6-luna op de 22 talen, maar 3,5x de prijs van gpt-6-luna, zwakker CJK (93) en verloor een Hebreeuws batchantwoord.'),
    ('DeepInfra', 1, 'google/gemini-3.1-flash-lite', 'openrouter|google/gemini-3.1-flash-lite', A, 'Zelfde model; DeepInfra-lijstprijs output $1,50 per miljoen.'),
    ('DeepInfra', 2, 'ByteDance/Seed-2.0-mini', 'openrouter|bytedance-seed/seed-2.0-mini', A, 'DeepInfra-lijstprijs output $0,40 per miljoen.'),
    ('DeepInfra', 3, 'deepseek-ai/DeepSeek-V4.1-Flash', 'openrouter|deepseek/deepseek-v4.1-flash', A, 'DeepInfra-lijstprijs output $0,60 per miljoen: duurder dan op OpenRouter.'),
    ('Anthropic (custom)', 1, 'claude-haiku-5-5', 'openrouter|anthropic/claude-haiku-5.5', A, 'Goedkoopste Claude; geen uitval; CJK-oordeel 96.'),
]
lookup = {(r['prov'] + '|' + r['model']): r for r in r2}
fill_for = {'OpenRouter': '#FFFFFF', 'DeepSeek': '#F2F2F2', 'Groq': '#FFFFFF', 'OpenAI': '#F2F2F2', 'DeepInfra': '#FFFFFF', 'Anthropic (custom)': '#F2F2F2'}
first_row = {}
first_q = {}
prev = None
for i, (prov, rank, mid, key, basis, note) in enumerate(advice):
    x = 4 + i
    e = x + 1
    r = lookup[key]
    if prov != prev:
        first_row[prov] = e
        first_q[prov] = r['q22']
        prev = prov
    fill = fill_for[prov]

    def fm(**k):
        return wb.add_format(dict({'font_name': 'Arial', 'font_size': 10, 'valign': 'vcenter', 'bg_color': fill}, **k))

    bold = rank == 1
    wa.write(x, 0, prov, fm(bold=bold))
    wa.write_number(x, 1, rank, fm(bold=bold))
    wa.write(x, 2, mid, fm(bold=bold))
    wa.write(x, 3, key, fm(font_color='#7F7F7F'))
    wa.write(x, 4, basis, fm())
    mk = 'MATCH($D%d,\'Ronde 2\'!$V$2:$V$%d,0)' % (e, last2)
    rng = lambda c: "'Ronde 2'!$%s$2:$%s$%d" % (c, c, last2)
    wa.write_formula(x, 5, '=INDEX(%s,%s)' % (rng('C'), mk), fm(num_format='0.0', bold=bold), r['q22'])
    wa.write_formula(x, 6, '=INDEX(%s,%s)' % (rng('G'), mk), fm(num_format='0', bold=bold), r['cjk_mean'])
    wa.write_formula(x, 7, '=INDEX(%s,%s)' % (rng('H'), mk), fm(num_format='0.0%'), r['fail'])
    wa.write_formula(x, 8, '=INDEX(%s,%s)' % (rng('K'), mk), fm(num_format='0.0'), r['secs'])
    wa.write_formula(x, 9, '=INDEX(%s,%s)' % (rng('J'), mk), fm(num_format='$0.0000'), r['cost_per_1000'])
    wa.write_formula(x, 10, '=J%d*100' % e, fm(num_format='$0.00'), r['cost_per_1000'] * 100)
    wa.write_formula(x, 11, '=F%d-F%d' % (e, first_row[prov]), fm(num_format='+0.0;-0.0;0.0'), r['q22'] - first_q[prov])
    wa.write(x, 12, note, fm())
wa.freeze_panes(4, 3)
wa.set_column(0, 0, 18)
wa.set_column(1, 1, 6)
wa.set_column(2, 2, 34)
wa.set_column(3, 3, 40)
wa.set_column(4, 4, 22)
wa.set_column(5, 11, 14)
wa.set_column(12, 12, 80)
wa.write(4 + len(advice) + 1, 0, 'Kosten per 100.000 zinnen is een indicatie: een gemiddelde pagina heeft 50-60 segmenten, vertaald naar tientallen talen. Groq heeft geen goede reserve: qwen3.8-27b vertaalt ~19% van het Japans naar het Chinees en gpt-oss-20b valt in 8% van de segmenten uit.', f_sub)
wa.activate()

# ------------------------------------------------------------------ Per taal (ronde 1)
wl.write(0, 0, 'Provider', f_head)
wl.write(0, 1, 'Model', f_head)
for c, code in enumerate(codes):
    wl.write(0, 2 + c, code, f_head)
wl.write(0, 2 + len(codes), 'Gewogen gemiddelde', f_head)
wl.write(0, 3 + len(codes), 'Gewogen gemiddelde zonder zh/ja/ko', f_head)
wl.set_row(0, 48)
wl.write(1, 0, 'Gewicht', f_bold)
wl.write(1, 1, 'grote talen 2, overige 1', f_text)
for c, w in enumerate(weights):
    wl.write_number(1, 2 + c, w, f_in_int)
c1, c2 = 2, 1 + len(codes)
col = xlsxwriter.utility.xl_col_to_name
cj0, cj1 = col(2 + cjk_idx[0]), col(2 + cjk_idx[-1])
for i, r in enumerate(rows):
    x = 2 + i
    e = x + 1
    wl.write(x, 0, r['label'], f_text)
    wl.write(x, 1, r['model'], f_text)
    for c, code in enumerate(codes):
        wl.write_number(x, 2 + c, r['per'][code], f_in)
    avg = sum(r['per'][code] * w for code, w in zip(codes, weights)) / sum(weights)
    wl.write_formula(x, 2 + len(codes), '=SUMPRODUCT(%s%d:%s%d,$%s$2:$%s$2)/SUM($%s$2:$%s$2)' % (
        col(c1), e, col(c2), e, col(c1), col(c2), col(c1), col(c2)), f_score, avg)
    num = sum(r['per'][code] * w for code, w in zip(codes, weights)) - sum(r['per'][codes[k]] * weights[k] for k in cjk_idx)
    den = sum(weights) - sum(weights[k] for k in cjk_idx)
    wl.write_formula(x, 3 + len(codes), '=(SUMPRODUCT(%s%d:%s%d,$%s$2:$%s$2)-SUMPRODUCT(%s%d:%s%d,$%s$2:$%s$2))/(SUM($%s$2:$%s$2)-SUM($%s$2:$%s$2))' % (
        col(c1), e, col(c2), e, col(c1), col(c2), cj0, e, cj1, e, cj0, cj1, col(c1), col(c2), cj0, cj1), f_score, num / den)
wl.freeze_panes(2, 2)
wl.set_column(0, 0, 12)
wl.set_column(1, 1, 38)
wl.set_column(2, 1 + len(codes), 6)
wl.set_column(2 + len(codes), 3 + len(codes), 16)
wl.conditional_format(2, 2, 1 + n, 1 + len(codes), {'type': '3_color_scale', 'min_color': '#F8696B', 'mid_color': '#FFEB84', 'max_color': '#63BE7B'})
wl.write(n + 3, 0, 'Ronde 1. Scores per taal zijn afgerond op 1 decimaal, dus de gewogen gemiddelden wijken licht af van de kwaliteit in Ronde 1. Chinees, Japans en Koreaans scoren bij alle modellen laag omdat chrF daar met één referentie te streng is (zie CJK-test).', f_sub)

# ------------------------------------------------------------------ Screening
sh = ['Provider', 'Model', 'Prijs invoer ($ per miljoen)', 'Prijs uitvoer ($ per miljoen)', 'Screeningscore (24 zinnen)',
      'Uitval (van 24)', 'Parameter om denken te beperken', 'Uitkomst']
for c, h in enumerate(sh):
    wsn.write(0, c, h, f_head)
wsn.set_row(0, 48)
why = {'error': 'Afgevallen: API-fout', 'thinks': 'Afgevallen: denkt en kan niet uit', 'mandatory': 'Afgevallen: denken verplicht', 'budget': 'Niet gemeten: budget'}
screen.sort(key=lambda s: (s['label'], not s['ok'], -(s.get('screen') or 0)))
for i, s in enumerate(screen):
    x = i + 1
    wsn.write(x, 0, s['label'], f_text)
    wsn.write(x, 1, s['model'], f_text)
    if s.get('in') is not None:
        wsn.write_number(x, 2, s['in'], f_in_price)
    if s.get('out') is not None:
        wsn.write_number(x, 3, s['out'], f_in_price)
    if s['ok']:
        wsn.write_number(x, 4, s['screen'], f_in)
        wsn.write_number(x, 5, s['fail'], f_in_int)
        p = s.get('param') or {}
        wsn.write(x, 6, ', '.join('%s=%s' % (k, json.dumps(v).strip('"')) for k, v in p.items()) or 'geen nodig', f_text)
        wsn.write(x, 7, 'Bruikbaar' if s['fail'] == 0 else 'Uitval in screening', f_text)
    else:
        wsn.write(x, 7, why.get(s.get('why'), 'Afgevallen'), f_text)
wsn.freeze_panes(1, 2)
wsn.autofilter(0, 0, len(screen), 7)
wsn.set_column(0, 0, 12)
wsn.set_column(1, 1, 44)
wsn.set_column(2, 5, 14)
wsn.set_column(6, 7, 34)
wsn.write(len(screen) + 2, 0, 'De screening gebruikt maar 3 aanroepen per model (24 zinnen) en is een grove zeef; de echte uitslag staat in Ronde 1 en Ronde 2.', f_sub)

# ------------------------------------------------------------------ Uitleg
wu.set_column(0, 0, 18)
wu.set_column(1, 1, 130)
wu.write(0, 0, 'Hoe dit is gemeten', f_title)
info = [
    ('Datum', '11 november 2026'),
    ('Doel', 'Per API-provider het goedkoopste model kiezen dat goed vertaalt in veel talen (met nadruk op grote talen) en niet denkt.'),
    ('Ronde 1', 'Voorselectie van 104 OpenRouter-modellen (en 2 DeepSeek, 5 Groq), daarna 46 finalisten op 25 talen met FLORES-200 devtest (24 zinnen per taal), kwaliteit met chrF++.'),
    ('Ronde 2', 'De top 3 per API (12 modellen) opnieuw op een nieuwe steekproef van 48 zinnen per taal. De 22 talen buiten zh/ja/ko met chrF++; Chinees, Japans en Koreaans door drie LLM-rechters (zie CJK-test).'),
    ('Aanroep', 'Zelfde vorm als de plugin: systeemprompt, JSON-batches van 8 zinnen, temperatuur 0. Daarnaast 6 zinnen met HTML, placeholders, URLs en getallen om te controleren dat opmaak behouden blijft.'),
    ('Kwaliteit', 'chrF++ (hoger is beter) tegen de referentie, gewogen gemiddelde over de talen (grote talen 2, overige 1). Ruis ronde 1: ~0,8 punt tussen twee modellen; ronde 2 (48 zinnen) ~0,6 punt.'),
    ('Uitval', "Percentage segmenten zonder bruikbare vertaling (ongeldige JSON of ontbrekend). In de plugin blijft zo'n pagina onvertaald. 1-2 ontbrekende segmenten op 1200 is ruis."),
    ('Denken', 'Gemeten via gerapporteerde denktokens en daarna met een parameter teruggebracht. Beoordeeld op Nederlands en Japans. Grens: 20 denktokens per aanroep.'),
    ('Kosten', 'Werkelijk verbruikte tokens maal lijstprijs. OpenRouter meldt de kosten zelf. DeepSeek en Groq: prijzen uit tools/model-benchmark/prices.json (DeepSeek op piektarief, daluren de helft).'),
    ('Selectieregel', "Poort: geen of vrijwel geen uitval, opmaak >= 80%, geen denken. Daarna kwaliteit eerst: modellen binnen de ruis van de beste gelden als gelijk en daar wint de goedkoopste (stabiele ID's vooraan). OpenAI is op verzoek op prijs gekozen."),
    ('Aannames', 'Groq vermeldt geen prijs voor qwen3.6-27b: aangenomen gelijk aan qwen3.8-27b. Groq-kosten zijn lijstprijzen; de gratis laag kost niets. OpenAI, DeepInfra en Anthropic zijn niet rechtstreeks gemeten: afgeleid uit hetzelfde model op OpenRouter.'),
    ('Beperkingen', 'Richting Engels/Nederlands/Duits naar andere talen, niet omgekeerd. LLM-rechters kunnen een eigen familie bevoordelen: daarom drie families, de mediaan, en een controle van de overeenkomst. Geen beoordeling door menselijke lezers.'),
    ('Verbruik', 'OpenRouter ongeveer $2,1 (ronde 1) en $7,4 (ronde 2 incl. rechters), DeepSeek ongeveer $0,3 (ronde 1) en $0,5 (ronde 2) echt verbruikt.'),
    ('Script', 'tools/model-benchmark/benchmark.py, rerun.py en build_xlsx.py in de plugin-repo (niet in de wordpress.org-release).'),
]
for i, (k, v) in enumerate(info):
    wu.write(2 + i, 0, k, F(bold=True, valign='top'))
    wu.write(2 + i, 1, v, f_wrap)
    wu.set_row(2 + i, 30 if len(v) > 125 else 16)

wb.close()
print('written', OUT, len(rows), 'round 1 rows,', len(r2), 'round 2 rows,', len(screen), 'screening rows')
