Je werkt in een Windows ontwikkelomgeving met Laragon (Apache, MySQL). De productieomgeving is Linux. De website is netcare.nl met de plugin ai-translate. Logbestanden staan in d:\laragon\log\netcare.

Het theme wat we gebruiken is NetCare in d:\laragon\wp-content\themes

We werken aan de plugin ai-translate. Alleen verder zoeken in codebase als daar specifiek om wordt gevraagd. Anders wordt het ontwikkel proces te traag. 

Plugin beschrijving :
Deze #codebase is een Wordpress plugin die sites kan vertalen in diverse talen. De admin kan kiezen welke talen zichtbaar zijn voor de gebruiker via een vlag onderin de pagina met een popup waarin de geselecteerde talen worden getoond. Verder zijn er ook talen die niet zichtbaar zijn maar wel gedetecteerd worden via de url of cookie. Ook die kan de beheerder kiezen. Die talen krijgen een eigen url /langcode/  

🎯 Regels voor communicatie door de AI met de gebruiker
- Altijd in het Nederlands antwoorden.
- Niet continue om bevestiging vragen. Als een probleem wordt beschreven, moet het worden opgelost. 
- Als je antwoord geeft in "ASK" modus. Geef de hele functie. In agent mode kan je wijzigingen wel doorvoeren.
- Geef regelnummers en bestands namen mee in het antwoord. Niet in edit of agent mode. 
- De productie omgeving is Linux, de ontwikkelomgeving is windows. Dus linux commando's geven en niet windows. Deze omgeving is de ontwikkel omgeving.
- De AI moet geen opdrachten geven aan de gebruiker om code te controleren. Dat moet de AI zelf doen.
- Vraag de gebruiker niet dingen te controleren in code. Doe dat zelf en geef een grondige analyse of advies.
- In agent en edit mode moet de AI de analyses en code wijzigingen zelf doen
- Code, Comments in code, log events, instructies, html output aleemal  allemaal ENGELS

Gebruikte technologieen:
- php
- wordpress #fetch https://developer.wordpress.org/plugins/
- javascript
- css
- De omgeving gebruikt Windows Powershell. 
- MCP tools gebruiken en niet shell commando's 

Plugin-structuur : ai-translate

Hoofdpluginbestand /

ai-translate.php: Bevat activatie/deactivatie hooks, initialisatie en registratie van filters/actions.

Includes map

/includes/admin-page.php : Admin‑instellingenpagina, toont velden en slaat opties op.

/includes/class-ai-translate.php : Alle vertaalkernel: API‑calls, caching, vertaallogica.

Assets map

/assets/flags/  Vlaggen per taal (PNG).
 
/assets/ JavaScript en CSS voor de language switcher.

🎯 Regels voor codewijzigingen
Lint‑controle is cruciaal

- Eerst run je Intelephense zie en verhelp alle fouten/warnings vóór je begint.
- Niet om problemen heen programmeren als het een bug is. Zoek de bug in plaats van repareren. 
- DocBlocks en Commentaren in het engels. 
- Eerst lezen, dan schrijven
- Vermijd regex en preg_replace waar mogelijk. Gebruik DOM parser en php/wordpress functies voor HTML handling
- Geen vragen stellen als het antwoord in deze instructies staan.
- Geen commentaren toevoegen als NIEUW of Bestaande code. Dat is alleen vervuilend.
- Geen lokele verwijzingen opnemen. Altijd generieke oplossingen
- Begrijp hoe de bestaande code werkt.
- Voorkom dubbele code door eerst te zoeken naar bestaande functies.
- Minimaal aantal API‑calls.
- Cache vertalingen in transient of eigen optie.
- Geen nieuwe calls voor reeds vertaalde pagina’s.
- Volg WordPress‑conventies
- Gebruik wp_enqueue_script(), register_setting(), add_action(), enzovoort.
- Houd je aan Coding Standards.
- Veilige, minimale wijzigingen
- Breek niets buiten het beoogde bereik.
- Als er meerdere manieren zijn: kies de veiligste.
- Structuur en documentatie behouden.
- Geen debug over verklarende teksten in de functie zelf, tenzij echt nodig om te begrijpen waarom.
- Houd namen en mappen consistent.
- Verwijderen = echt verwijderen niet als commentaar verwijderen
- Robuuste, herbruikbare functies
- Generaliseer waar mogelijk.
- Geen recursieve aanroepen die tot loops leiden.
- Maak geen nieuwe bestanden zonder opdracht.  Werk binnen de 3 bestaande PHP‑bestanden tenzij anders gevraagd.
- Eindcheck op linter‑fouten

Na al je wijzigingen: draai opnieuw Intelephense 0 errors, 0 warnings. 🚨

⚠️ Valkuilen om te vermijden
→ Shortcodes niet vertalen (dat is de standaard)
→ Controleer of je niets breekt dat via [shortcode] wordt aangeroepen.
→ Scripts/CSS per ongeluk vertalen
→ Filter je vertaalbare strings; overgeslagen assets blijven onaangeroerd.
→ Default taal via API
→ Stel fallback in, maar gebruik nooit onbedoeld de API om de standaard taal op te halen.
→ Functies in admin-page.php vs. class-ai-translate.php
→ Instellingen opslaan én óók gebruiken in de translate‑klasse, niet enkel registreren.