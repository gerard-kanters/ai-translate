# AI Translate Cache Cleanup Script

Script om dubbele homepage's en attachments op te sporen en te verwijderen uit de AI Translate cache.

## Gebruik

### Basis gebruik (vioolles.net)

```bash
cd /opt/ai-translate/scripts
chmod +x find-duplicate-homepages-and-attachments.sh
./find-duplicate-homepages-and-attachments.sh
```

### Voor andere websites

Je kunt environment variabelen gebruiken om de configuratie aan te passen:

```bash
export CACHE_DIR="/var/www/netcare.nl/wp-content/uploads/ai-translate/cache"
export DB_NAME="netcare_db"
export DB_USER="netcare_user"
export DB_PASS="password"
export DB_HOST="localhost"
export TABLE_PREFIX="grfvs_"

./find-duplicate-homepages-and-attachments.sh
```

Of pas de defaults aan in het script zelf (regels 9-14).

## Wat doet het script?

1. **Scant de database** voor cache metadata entries
2. **Vindt duplicate homepages**: Alle entries waar `post_id = 0` per taal (houdt de eerste, markeert de rest als duplicate)
3. **Vindt attachments**: Alle entries waar de post een `attachment` type is
4. **Toont een overzicht** met aantal en details van elk probleem
5. **Vraagt om bevestiging** voordat bestanden worden verwijderd
6. **Verwijdert optioneel** ook de database entries

## Output

Het script toont:
- Aantal duplicate homepages per taal
- Aantal attachments
- Details van elk bestand (pad, taal, grootte, datum)
- Optie om te verwijderen (bestanden EN database entries)

## Veiligheid

- **Geen automatische verwijdering**: Je moet expliciet "yes" typen
- **Dubbele bevestiging**: Eerst voor bestanden, dan voor database
- **Toont details**: Je ziet precies wat verwijderd wordt
- **Niet-destructief**: Je kunt het script eerst draaien om alleen te kijken

## Voorbeelden

### Alleen kijken (geen verwijdering)
```bash
./find-duplicate-homepages-and-attachments.sh
# Typ "no" wanneer gevraagd wordt om te verwijderen
```

### Verwijderen met database cleanup
```bash
./find-duplicate-homepages-and-attachments.sh
# Typ "yes" voor bestanden verwijderen
# Typ "yes" voor database entries verwijderen
```

## Vereisten

- Bash shell
- MySQL client (`mysql` command)
- Toegang tot de database
- Toegang tot de cache directory
- `du` en `stat` commands (standaard op Linux)

