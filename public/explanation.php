<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
$DEBUG = isset($_GET['debug']);
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranglijst Deltavliegen – Toelichting</title>
  <link rel="stylesheet" href="assets/style.css">
  <meta name="description" content="Ranglijst Deltavliegen – Toelichting">
</head>
<body class="container">
<header class="topbar">
  <h1><a href="ranking.php" class="logo">Ranglijst Deltavliegen</a></h1>
</header>


<nav class="card" style="margin:1rem 0; padding:.5rem 1rem;">
  <a href="home.php">Home</a> ·
  <a href="ranking.php">Klasse 1</a> ·
  <a href="sportclass.php">Sportklasse</a> ·
  <a href="competitionlist.php">Wedstrijden</a> ·
  <a href="explanation.php"><strong>Toelichting</strong></a>
</nav>


<main class="card">
  <h2>Toelichting</h2>

    <p>
      De ranglijst deltavliegen geeft een actuele inschatting van het prestatieniveau van Nederlandse wedstrijdpiloten.
      De lijst wordt gebruikt bij de selectie van de Nederlandse kernploeg en voor teamafvaardigingen naar EK’s en WK’s.
    </p>

    <h3>Uitgangspunten</h3>
    <ul>
      <li>De ranglijst is een hulpmiddel voor de keuze van het Nederlands team bij internationale uitzendingen (EK en WK).</li>
      <li>Presteren in wedstrijden is de meest relevante maat voor niveau.</li>
      <li>Het NK is de belangrijkste onderlinge graadmeter tussen Nederlandse piloten.</li>
      <li>De wereldranglijst (WPRS) bevat ook resultaten van o.a. de Dutch Open.</li>
      <li>WPRS‑punten weerspiegelen internationale prestatie en wedstrijdactiviteit.</li>
      <li>Iedere piloot kan meedoen aan open wedstrijden om ervaring op te doen en WPRS‑punten te scoren — die tellen mee voor de Nederlandse ranglijst.</li>
      <li>Het missen van een NK heeft een gebalanceerde impact: deelname wordt gestimuleerd, maar een onfortuinlijke afwezigheid is niet rampzalig.</li>
    </ul>

    <h3>Huidige rekenmethode (sinds 2008)</h3>
<p>
  De ranglijstpunten per jaar zijn de som van drie genormaliseerde deel‑scores (elk tussen <strong>0</strong> en <strong>1</strong>),
  met wegingsfactoren <strong>100</strong>, <strong>50</strong> en <strong>150</strong> die het relatieve belang aangeven:
</p>
<pre><code>Ranglijstpunten(jaar) = 100 × PositieScoreNK(jaar)
                       +  50 × PositieScoreNK(jaar − 1)
                       + 150 × WPRS_score(jaar)

PositieScoreNK(jaar) = 1 − (positie − 1) ÷ aantal_deelnemers_NK(jaar)
WPRS_score(jaar)     = WPRS_oct1(piloot, jaar) ÷ WPRS_oct1(best_gerankte_NL, jaar)</code></pre>
<p class="muted">
  Toelichting: de WPRS‑stand is de positie per <strong>1 oktober</strong> van het betreffende jaar.
  De NK‑score schaalt lineair met de behaalde positie en het aantal deelnemers.
</p>

<h3>Voorbeeldberekening</h3>
<p>Compact voorbeeld voor jaar <strong>2025</strong>:</p>
<ul>
  <li><strong>NK 2025</strong>: positie 4 van 40 → <em>PositieScoreNK(2025)</em> = 1 − (4 − 1) ÷ 40 = <strong>0,925</strong> → component = 100 × 0,925 = <strong>92,50</strong></li>
  <li><strong>NK 2024</strong>: positie 10 van 38 → <em>PositieScoreNK(2024)</em> = 1 − (10 − 1) ÷ 38 ≈ <strong>0,7632</strong> → component = 50 × 0,7632 ≈ <strong>38,16</strong></li>
  <li><strong>WPRS per 1 okt 2025</strong>: piloot 124,5 pt; beste NL 160,0 pt → <em>WPRS_score</em> = 124,5 ÷ 160,0 = <strong>0,7781</strong> → component = 150 × 0,7781 ≈ <strong>116,72</strong></li>
</ul>
<p><strong>Totaal</strong> ≈ 92,50 + 38,16 + 116,72 = <strong>247,38</strong> punten.</p>
<p class="muted"><small>NB: op de site tonen we 2 decimalen; intern kan met meer precisie worden gerekend.</small></p>
<h3>Historische methode (… – 2008)</h3>
    <p>In de oudere methode telden <strong>drie historische NK’s</strong> mee met aflopende weging:</p>
    <pre><code>Ranglijstpunten(jaar) = 100 × PositieScoreNK(jaar)
                       +  80 × PositieScoreNK(jaar − 1)
                       +  60 × PositieScoreNK(jaar − 2)

PositieScoreNK(jaar) = 1 − (positie − 1) ÷ aantal_deelnemers_NK(jaar)</code></pre>

    <h3>Publicatie & afronding</h3>
    <ul>
      <li>Een jaarranking wordt gepubliceerd zodra alle drie componenten beschikbaar zijn:
          NK (huidig jaar), NK (vorig jaar) en WPRS per 1 oktober.</li>
      <li>Scores worden op de site weergegeven met twee decimalen; intern wordt met volledige precisie gerekend.</li>
      <li>Bij ontbrekende of ongeldige invoer voor een component telt die component als 0 voor de betreffende piloot.</li>
    </ul>
</main>

<footer class="muted" style="margin-top:2rem;">
  <p>Stijl geïnspireerd op CIVL rankings.</p>
</footer>
</body>
</html>
