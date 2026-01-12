#!/usr/bin/env python3
import os
import re

def analyze_po_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Tel totaal aantal msgid entries
    msgid_count = len(re.findall(r'^msgid "', content, re.MULTILINE))

    # Tel aantal lege msgstr entries (niet vertaald)
    empty_msgstr = len(re.findall(r'^msgstr ""$', content, re.MULTILINE))

    # Tel aantal niet-lege msgstr entries (wel vertaald)
    filled_msgstr = msgid_count - empty_msgstr

    # Bereken percentage compleet
    percentage = (filled_msgstr / msgid_count * 100) if msgid_count > 0 else 0

    return {
        'total_strings': msgid_count,
        'translated_strings': filled_msgstr,
        'untranslated_strings': empty_msgstr,
        'percentage_complete': round(percentage, 1)
    }

# Analyseer alle .po bestanden
languages_dir = 'languages'
results = {}

for filename in os.listdir(languages_dir):
    if filename.endswith('.po'):
        lang_code = filename.replace('ai-translate-', '').replace('.po', '')
        filepath = os.path.join(languages_dir, filename)
        results[lang_code] = analyze_po_file(filepath)

# Print resultaten
print('Overzicht vertaalcompleetheid per taal:')
print('=' * 60)
print(f"{'Taal':<12} {'Vertaald':<10} {'Totaal':<8} {'Compleet':<10} {'Ontbrekend':<12}")
print('-' * 60)

for lang, data in sorted(results.items()):
    print(f"{lang:<12} {data['translated_strings']:<10} {data['total_strings']:<8} {data['percentage_complete']:<10}% {data['untranslated_strings']:<12}")

print(f"\nTotaal aantal te vertalen strings (vanuit .pot): 179")