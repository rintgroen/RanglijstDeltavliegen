<?php
define('APP_AREA', 'public');
require_once __DIR__ . '/../includes/app.php';

app_enable_debug();
app_page_start(app_site_name() . ' - Toelichting', [
    'active_public' => 'explanation',
    'description' => 'Toelichting op de berekening van de Nederlandse ranglijst deltavliegen.',
]);
?>
<main class="card">
  <h1>Toelichting</h1>

  <p>
    De ranglijst deltavliegen geeft een actuele inschatting van het prestatieniveau van Nederlandse wedstrijdpiloten.
    De lijst wordt gebruikt bij de selectie van de Nederlandse kernploeg en voor teamafvaardigingen naar EK's en WK's.
  </p>

  <h2>Uitgangspunten</h2>
  <ul>
    <li>De ranglijst is een hulpmiddel voor de keuze van het Nederlands team bij internationale uitzendingen.</li>
    <li>Presteren in wedstrijden is de meest relevante maat voor niveau.</li>
    <li>Het NK is de belangrijkste onderlinge graadmeter tussen Nederlandse piloten.</li>
    <li>De wereldranglijst (WPRS) bevat ook resultaten van internationale open wedstrijden.</li>
    <li>WPRS-punten weerspiegelen internationale prestatie en wedstrijdactiviteit.</li>
    <li>Het missen van een NK heeft impact, maar is niet direct doorslaggevend.</li>
  </ul>

  <h2>Huidige rekenmethode</h2>
  <p>
    De ranglijstpunten per jaar zijn de som van drie genormaliseerde deelscores, met wegingsfactoren
    <strong>100</strong>, <strong>50</strong> en <strong>150</strong>.
  </p>
  <pre><code>Ranglijstpunten(jaar) = 100 x PositieScoreNK(jaar)
                       +  50 x PositieScoreNK(jaar - 1)
                       + 150 x WPRS_score(jaar)

PositieScoreNK(jaar) = 1 - (positie - 1) / aantal_deelnemers_NK(jaar)
WPRS_score(jaar)     = WPRS_oct1(piloot, jaar) / WPRS_oct1(best_gerankte_NL, jaar)</code></pre>
  <p class="muted">
    De WPRS-stand is de positie per 1 oktober van het betreffende jaar. Scores worden op de site weergegeven met twee decimalen.
  </p>

  <h2>Voorbeeldberekening</h2>
  <ul>
    <li><strong>NK 2025</strong>: positie 4 van 40 geeft 92,50 punten.</li>
    <li><strong>NK 2024</strong>: positie 10 van 38 geeft ongeveer 38,16 punten na weging.</li>
    <li><strong>WPRS 2025</strong>: 124,5 punten tegenover 160,0 voor de beste Nederlandse piloot geeft ongeveer 116,72 punten na weging.</li>
  </ul>
  <p><strong>Totaal</strong>: ongeveer 247,38 punten.</p>

  <h2>Historische methode tot en met 2008</h2>
  <p>In de oudere methode telden drie historische NK's mee met aflopende weging:</p>
  <pre><code>Ranglijstpunten(jaar) = 100 x PositieScoreNK(jaar)
                       +  80 x PositieScoreNK(jaar - 1)
                       +  60 x PositieScoreNK(jaar - 2)</code></pre>

  <h2>Publicatie</h2>
  <ul>
    <li>Een jaarranking wordt gepubliceerd zodra alle benodigde componenten beschikbaar zijn.</li>
    <li>Bij ontbrekende of ongeldige invoer voor een component telt die component als 0 voor de betreffende piloot.</li>
    <li>Intern wordt met volledige precisie gerekend; op de site worden waarden afgerond getoond.</li>
  </ul>
</main>
<?php app_page_end(); ?>
