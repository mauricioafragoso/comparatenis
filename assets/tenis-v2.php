<?php
// ── Segurança: validar ID ──────────────────────────────────────────────────────
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if (!preg_match('/^[a-z0-9\-]{3,100}$/', $id)) {
    http_response_code(404);
    exit('Tênis não encontrado.');
}

// ── Carregar catálogo ─────────────────────────────────────────────────────────
$catalogoPath = __DIR__ . '/_data/catalogo.json';
if (!file_exists($catalogoPath)) { http_response_code(500); exit('Erro interno.'); }
$catalogo = json_decode(file_get_contents($catalogoPath), true);
if (!is_array($catalogo)) { http_response_code(500); exit('Erro interno.'); }

// ── Encontrar tênis ───────────────────────────────────────────────────────────
$shoe = null;
foreach ($catalogo as $item) {
    if (isset($item['id']) && $item['id'] === $id) { $shoe = $item; break; }
}
if (!$shoe) { http_response_code(404); exit('Tênis não encontrado.'); }

// ── Sugestões — algoritmo de score (item 17) ──────────────────────────────────
$catMap = ['daily_trainer'=>0,'max_cushion'=>1,'performance'=>2,'race'=>3];
$amortMap = ['baixo'=>0,'medio'=>1,'alto'=>2,'maximo'=>3];

function scoreSugestao(array $s, array $shoe, array $catMap, array $amortMap): int {
    $score = 0;
    // Pontos positivos
    if (($s['categoria']??'') === ($shoe['categoria']??'')) $score += 50;
    $usoComum = count(array_intersect($s['tipos_uso']??[], $shoe['tipos_uso']??[]));
    if ($usoComum > 0) $score += 25;
    if (($s['perfil_corredor']??'') === ($shoe['perfil_corredor']??'')) $score += 15;
    if (($s['amortecimento_nivel']??'') === ($shoe['amortecimento_nivel']??'')) $score += 15;
    if (($s['possui_placa']??false) === ($shoe['possui_placa']??false)) $score += 10;
    if (($s['estabilidade_tipo']??'') === ($shoe['estabilidade_tipo']??'')) $score += 10;
    $diffNota = abs(($s['nota_geral']??0) - ($shoe['nota_geral']??0));
    if ($diffNota <= 1) $score += 8;
    if (($s['drop_mm']??0) > 0 && ($shoe['drop_mm']??0) > 0 && abs($s['drop_mm'] - $shoe['drop_mm']) <= 2) $score += 5;
    if (($s['peso_g']??0) > 0 && ($shoe['peso_g']??0) > 0 && abs($s['peso_g'] - $shoe['peso_g']) <= 30) $score += 5;
    $diffAno = abs(($s['ano_lancamento']??0) - ($shoe['ano_lancamento']??0));
    if ($diffAno <= 1) $score += 3;
    // Penalidades
    $catS = $s['categoria']??''; $catShoe = $shoe['categoria']??'';
    if (($catS==='race' && in_array($catShoe,['daily_trainer','max_cushion'])) ||
        ($catShoe==='race' && in_array($catS,['daily_trainer','max_cushion']))) $score -= 20;
    if ($diffNota > 2) $score -= 10;
    $amS = $amortMap[$s['amortecimento_nivel']??'']??-1;
    $amShoe = $amortMap[$shoe['amortecimento_nivel']??'']??-1;
    if ($amS >= 0 && $amShoe >= 0 && abs($amS - $amShoe) === 3) $score -= 10;
    return $score;
}

$candidatos = [];
foreach ($catalogo as $item) {
    if (($item['id']??'') === $id) continue;
    if (($item['genero']??'') !== ($shoe['genero']??'')) continue;
    if (!isset($item['id']) || !isset($item['modelo']) || !isset($item['categoria'])) continue;
    $item['_score'] = scoreSugestao($item, $shoe, $catMap, $amortMap);
    $item['_usoComum'] = count(array_intersect($item['tipos_uso']??[], $shoe['tipos_uso']??[]));
    $candidatos[] = $item;
}

usort($candidatos, function($a, $b) use ($shoe, $amortMap) {
    if ($b['_score'] !== $a['_score']) return $b['_score'] - $a['_score'];
    if (($b['categoria']??'') === ($shoe['categoria']??'') && ($a['categoria']??'') !== ($shoe['categoria']??'')) return 1;
    if (($a['categoria']??'') === ($shoe['categoria']??'') && ($b['categoria']??'') !== ($shoe['categoria']??'')) return -1;
    if ($b['_usoComum'] !== $a['_usoComum']) return $b['_usoComum'] - $a['_usoComum'];
    $dNA = abs(($a['nota_geral']??0) - ($shoe['nota_geral']??0));
    $dNB = abs(($b['nota_geral']??0) - ($shoe['nota_geral']??0));
    if ($dNA !== $dNB) return $dNA - $dNB;
    $amA = $amortMap[$a['amortecimento_nivel']??'']??-1;
    $amB = $amortMap[$b['amortecimento_nivel']??'']??-1;
    $amShoe = $amortMap[$shoe['amortecimento_nivel']??'']??-1;
    $dAmA = $amA >= 0 ? abs($amA - $amShoe) : 99;
    $dAmB = $amB >= 0 ? abs($amB - $amShoe) : 99;
    if ($dAmA !== $dAmB) return $dAmA - $dAmB;
    $popDiff = ($b['popular']??false) <=> ($a['popular']??false);
    if ($popDiff !== 0) return $popDiff;
    return ($b['ano_lancamento']??0) - ($a['ano_lancamento']??0);
});

// Regra de diversidade: max 2 da mesma marca
$sugestoes = [];
$marcaCount = [];
foreach ($candidatos as $item) {
    if (count($sugestoes) >= 3) break;
    $marca = $item['marca'] ?? '';
    if (($marcaCount[$marca] ?? 0) >= 2) continue;
    $sugestoes[] = $item;
    $marcaCount[$marca] = ($marcaCount[$marca] ?? 0) + 1;
}
// Fallback se ainda faltar
if (count($sugestoes) < 3) {
    foreach ($candidatos as $item) {
        if (count($sugestoes) >= 3) break;
        if (in_array($item['id'], array_column($sugestoes, 'id'))) continue;
        $sugestoes[] = $item;
    }
}

// ── Helpers PHP ───────────────────────────────────────────────────────────────
function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function lGenPHP(string $g): string {
    return ['masculino'=>'Masculino','feminino'=>'Feminino','unissex'=>'Unissex'][$g] ?? $g;
}
function lCatPHP(string $c): string {
    return ['daily_trainer'=>'Treino diário','max_cushion'=>'Máximo amortecimento','performance'=>'Performance','race'=>'Corrida / Prova'][$c] ?? $c;
}

$marca  = esc($shoe['marca']  ?? '');
$modelo = esc($shoe['modelo'] ?? '');
$genero = esc(lGenPHP($shoe['genero'] ?? ''));
$nota   = isset($shoe['nota_geral']) ? (int)$shoe['nota_geral'] : 0;
$peso   = isset($shoe['peso_g'])  && $shoe['peso_g']  > 0 ? (int)$shoe['peso_g']  : 0;
$drop   = isset($shoe['drop_mm']) && $shoe['drop_mm'] > 0 ? (int)$shoe['drop_mm'] : 0;

$pageTitle = $marca . ' ' . $modelo . ' ' . $genero . ' — Ficha técnica e review | ComparaTênis';
$descParts = ['Ficha técnica completa do ' . $shoe['marca'] . ' ' . $shoe['modelo'] . ' ' . lGenPHP($shoe['genero'] ?? '') . '.'];
if ($nota > 0) $descParts[] = 'Nota geral: ' . $nota . '/10.';
if ($peso > 0) $descParts[] = 'Peso: ' . $peso . 'g.';
if ($drop > 0) $descParts[] = 'Drop: ' . $drop . 'mm.';
$descParts[] = 'Veja amortecimento, drop, espuma e compare com outros modelos.';
$pageDesc  = esc(implode(' ', $descParts));
$canonUrl  = esc('https://comparatenis.com.br/tenis/' . $id);
$ogImage   = esc($shoe['imagem'] ?? '/assets/og-default.webp');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<meta name="description" content="<?= $pageDesc ?>">
<link rel="canonical" href="<?= $canonUrl ?>">
<meta name="robots" content="index, follow">
<script type="application/ld+json" id="schema-jsonld"></script>
<meta property="og:title" content="<?= esc($marca . ' ' . $modelo . ' ' . $genero . ' — Ficha técnica | ComparaTênis') ?>">
<meta property="og:description" content="<?= $pageDesc ?>">
<meta property="og:image" content="<?= $ogImage ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ComparaTênis">
<link rel="icon" href="/favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --orange: #FC4C02;
  --orange-light: #fff2ed;
  --black: #111111;
  --gray-mid: #666666;
  --gray-light: #F7F7F7;
  --border: #E5E5E5;
  --white: #FFFFFF;
  --font: 'Barlow', sans-serif;
  --font-display: 'Barlow Condensed', sans-serif;
  --col-label: 160px;
  --cream: #FFFAF3;
  --cream-border: #E8D3A5;
  --cream-alt: #FFF5E6;
}
body { font-family: var(--font); color: var(--black); background: var(--white); }

/* HERO */
.hero { padding: 3rem 2rem 3.5rem 6rem; text-align: left; background-image: url('/assets/hero-bg.webp'); background-size: cover; background-position: right 60%; background-repeat: no-repeat; position: relative; }
.hero::before { content: ''; position: absolute; inset: 0; background: linear-gradient(to right, rgba(0,0,0,0.45) 0%, rgba(0,0,0,0.35) 40%, rgba(0,0,0,0.10) 70%, rgba(0,0,0,0) 100%); }
.hero h1 { position: relative; z-index: 1; font-family: var(--font-display); font-size: 48px; font-weight: 800; letter-spacing: -1px; line-height: 1.05; margin-bottom: 8px; max-width: 700px; color: white; }
.hero h1 span { color: var(--orange); }
.hero p { position: relative; z-index: 1; font-size: 17px; color: rgba(255,255,255,0.88); max-width: 600px; margin: 0; line-height: 1.6; }

@media (max-width: 700px) {
  .hero { padding: 2.5rem 1.5rem; text-align: center; background: var(--gray-light); }
  .hero::before { display: none; }
  .hero h1 { font-size: 32px; color: var(--black); margin: 0 auto 8px; }
  .hero p { font-size: 15px; color: var(--gray-mid); margin: 0 auto; }
}

/* PAGE WRAP */
.page-wrap { max-width: 720px; margin: 0 auto; padding: 0 2rem 6rem; }

/* SHOE HEADER */
.shoe-header { display: grid; grid-template-columns: 300px 1fr; gap: 2rem; align-items: center; margin: 2rem 0 1.5rem; }
.shoe-img-wrap { border-radius: 14px; overflow: hidden; background: var(--cream); border: 1px solid var(--cream-border); display: flex; align-items: center; justify-content: center; }
.shoe-img-wrap img.shoe-photo { width: 100%; max-height: 320px; object-fit: contain; display: block; }
.shoe-img-wrap .shoe-placeholder-wrap { width: 100%; height: 300px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; }
.shoe-img-wrap .shoe-placeholder-wrap img { width: 80px; opacity: 0.2; }
.shoe-img-wrap .shoe-placeholder-wrap span { font-size: 11px; color: #bbb; }
.shoe-meta { display: flex; flex-direction: column; gap: 6px; padding-top: 4px; }
.shoe-meta-brand { font-size: 11px; color: var(--gray-mid); text-transform: uppercase; letter-spacing: 2px; font-weight: 600; }
.shoe-meta-name { font-family: var(--font-display); font-size: 36px; font-weight: 800; line-height: 1.05; letter-spacing: -0.5px; }
.shoe-meta-genero { font-size: 13px; color: var(--gray-mid); }
.shoe-meta-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.shoe-tag { font-size: 11px; color: var(--orange); background: var(--orange-light); border-radius: 4px; padding: 3px 8px; font-weight: 600; }
.shoe-meta-nota { margin-top: 8px; display: flex; align-items: center; gap: 10px; }
.nota-badge { font-family: var(--font-display); font-size: 40px; font-weight: 800; color: var(--orange); line-height: 1; }
.nota-label strong { display: block; font-size: 14px; color: var(--black); }
.nota-label span { font-size: 12px; color: var(--gray-mid); }
.shoe-meta-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.btn-ver-oferta { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--orange); color: white; border: none; padding: 11px 18px; border-radius: 8px; font-family: var(--font); font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; width: 100%; }
.btn-ver-oferta:hover { opacity: 0.9; }
.btn-ver-oferta.disabled { opacity: 0.4; pointer-events: none; cursor: default; }
.btn-compare-outro { display: inline-flex; align-items: center; gap: 8px; background: var(--white); color: var(--black); border: 1.5px solid var(--border); padding: 10px 18px; border-radius: 8px; font-family: var(--font); font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; width: 100%; justify-content: center; }
.btn-compare-outro:hover { border-color: var(--black); }
.share-btn { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; color: var(--gray-mid); font-family: var(--font); font-size: 12px; font-weight: 600; padding: 0; cursor: pointer; }
.share-btn:hover { color: var(--black); }

/* SECTION TITLE */
.section-title { font-family: var(--font-display); font-size: 12px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--gray-mid); padding: 2rem 0 0.75rem; margin-top: 1.5rem; display: flex; align-items: center; gap: 10px; }
.tag-pill { display: inline-flex; background: var(--orange-light); color: var(--orange); border-radius: 20px; padding: 2px 10px; font-size: 11px; font-weight: 700; letter-spacing: 0; text-transform: none; }

/* TABELA SINGLE */
.single-row { display: grid; grid-template-columns: var(--col-label) 1fr; border-bottom: 1px solid var(--border); }
.single-row:last-child { border-bottom: none; }
.single-label { padding: 12px 0; font-size: 14px; color: var(--gray-mid); }
.single-value { padding: 12px 16px; font-size: 14px; font-weight: 600; background: var(--cream); border-left: 3px solid var(--cream-border); color: var(--black); }
.single-row:nth-child(even) .single-value { background: var(--cream-alt); }

/* SCORES SINGLE */
.score-single-row { display: grid; grid-template-columns: var(--col-label) 1fr; border-bottom: 1px solid var(--border); }
.score-single-row:last-child { border-bottom: none; }
.score-single-label { padding: 12px 0; font-size: 14px; color: var(--gray-mid); }
.score-single-cell { padding: 14px 16px; display: flex; flex-direction: column; gap: 5px; background: var(--cream); border-left: 3px solid var(--cream-border); }
.score-single-row:nth-child(even) .score-single-cell { background: var(--cream-alt); }
.score-num { font-size: 13px; font-weight: 700; color: #7a4f00; display: inline-flex; align-items: center; }
.score-blocks { display: flex; gap: 2px; }
.score-block { height: 6px; border-radius: 2px; background: var(--border); flex: 1; }
.score-block.filled { background: var(--cream-border); }

/* PLACA CHIP */
.placa-chip { display: inline-flex; align-items: center; gap: 5px; border-radius: 5px; padding: 2px 8px; font-size: 13px; font-weight: 700; }
.placa-chip.sim { background: #e8f8ee; color: #1a7f3c; }
.placa-tipo { font-weight: 400; font-size: 12px; }

/* BARRA DE PESO */
.weight-bar-wrap { display: flex; flex-direction: column; gap: 4px; }
.weight-val { font-size: 14px; font-weight: 700; color: var(--black); }
.weight-track { position: relative; height: 6px; background: var(--border); border-radius: 3px; margin: 2px 0; }
.weight-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 3px; background: var(--cream-border); }
.weight-marker { position: absolute; top: -5px; width: 4px; height: 16px; border-radius: 2px; transform: translateX(-50%); background: #b89a6a; }
.weight-labels { display: flex; justify-content: space-between; font-size: 10px; color: #aaa; }

/* SOLADO SINGLE */
.sole-single-wrap { padding: 12px 16px; background: var(--cream); border-left: 3px solid var(--cream-border); display: flex; flex-direction: column; align-items: center; gap: 6px; }
.sole-single-wrap svg { width: 100%; height: auto; max-width: 220px; display: block; }
.sole-nums { font-size: 11px; color: var(--gray-mid); text-align: center; line-height: 1.6; }
.sole-nums strong { color: var(--black); }

/* BLOCO FINAL */
.page-footer { margin-top: 3rem; display: flex; flex-direction: column; gap: 1.5rem; }

/* CTA COMPRA */
.cta-section { background: var(--cream); border: 1px solid var(--cream-border); border-radius: 14px; padding: 1.5rem; display: flex; align-items: center; gap: 1.5rem; }
.cta-img-wrap { width: 120px; height: 120px; flex-shrink: 0; border-radius: 10px; overflow: hidden; background: var(--white); display: flex; align-items: center; justify-content: center; }
.cta-img-wrap img.shoe-photo { width: 100%; height: 100%; object-fit: contain; }
.cta-img-wrap img.shoe-placeholder { width: 56px; opacity: 0.2; }
.cta-info { flex: 1; }
.cta-brand { font-size: 10px; color: var(--gray-mid); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; margin-bottom: 2px; }
.cta-name { font-size: 18px; font-weight: 800; margin-bottom: 4px; }
.cta-genero { font-size: 12px; color: var(--gray-mid); margin-bottom: 12px; }
.cta-btn { display: inline-block; background: var(--orange); color: white; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 700; text-decoration: none; }
.cta-btn:hover { opacity: 0.9; }

/* BLOCO COMPARAR RODAPÉ — item 13/14 */
.footer-compare { background: var(--gray-light); border-radius: 14px; padding: 1.25rem 1.5rem; }
.footer-compare-title { font-family: var(--font-display); font-size: 18px; font-weight: 800; margin-bottom: 4px; }
.footer-compare-sub { font-size: 13px; color: var(--gray-mid); margin-bottom: 12px; }
.btn-compare-footer { display: inline-flex; align-items: center; gap: 8px; background: var(--orange); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
.btn-compare-footer:hover { opacity: 0.9; }

/* SUGESTÕES — item 15/16/18 */
.sugestoes-block { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 1.25rem 1.5rem; }
.sugestoes-title { font-family: var(--font-display); font-size: 13px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--gray-mid); margin-bottom: 10px; }
.sugestoes-list { display: flex; flex-direction: column; gap: 6px; }
.sugestao-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; text-decoration: none; color: var(--black); transition: border-color 0.15s, background 0.15s; cursor: pointer; }
.sugestao-item:hover { border-color: var(--orange); background: var(--orange-light); }
.sugestao-info { min-width: 0; flex: 1; }
.sugestao-brand { font-size: 10px; color: var(--gray-mid); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
.sugestao-name { font-size: 14px; font-weight: 700; }
.sugestao-meta { font-size: 11px; color: var(--gray-mid); }
.sugestao-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.sugestao-oferta { display: inline-block; font-size: 11px; font-weight: 700; color: var(--orange); background: var(--orange-light); border-radius: 5px; padding: 3px 8px; text-decoration: none; white-space: nowrap; }
.sugestao-oferta.disabled { opacity: 0.4; pointer-events: none; }
.sugestao-arrow { font-size: 16px; color: var(--gray-mid); }

/* COMPARTILHAR RODAPÉ */
.footer-share { text-align: center; }
.footer-share-btn { display: inline-flex; align-items: center; gap: 7px; background: none; border: 1.5px solid var(--border); color: var(--gray-mid); font-family: var(--font); font-size: 13px; font-weight: 600; padding: 8px 18px; border-radius: 20px; cursor: pointer; }
.footer-share-btn:hover { border-color: var(--black); color: var(--black); }

/* MOBILE */
@media (max-width: 700px) {
  .page-wrap { padding: 0 1rem 5rem; }
  .shoe-header { grid-template-columns: 1fr; grid-template-rows: auto auto; }
  .shoe-img-wrap { order: -1; }
  .shoe-img-wrap img.shoe-photo { max-height: 260px; }
  .shoe-img-wrap .shoe-placeholder-wrap { height: 200px; }
  .shoe-meta-name { font-size: 28px; }
  :root { --col-label: 90px; }
  .single-label, .single-value, .score-single-label { padding: 10px 8px; font-size: 12px; }
  .score-single-cell { padding: 10px 8px; }
  .cta-section { flex-direction: column; text-align: center; }
  .cta-img-wrap { width: 100px; height: 100px; }
}
</style>
</head>
<body>

<!-- HERO -->
<div class="hero">
  <h1><?= esc($shoe['marca'] . ' ') ?><span><?= esc($shoe['modelo']) ?></span></h1>
  <p id="page-subtitle"></p>
</div>

<div class="page-wrap">

  <!-- CABEÇALHO DO TÊNIS -->
  <div class="shoe-header">
    <div class="shoe-img-wrap" id="shoe-img-wrap"></div>
    <div class="shoe-meta">
      <div class="shoe-meta-brand" id="meta-brand"></div>
      <div class="shoe-meta-name" id="meta-name"></div>
      <div class="shoe-meta-genero" id="meta-genero"></div>
      <div class="shoe-meta-tags" id="meta-tags"></div>
      <div class="shoe-meta-nota" id="meta-nota"></div>
      <div class="shoe-meta-actions">
        <a class="btn-ver-oferta" id="btn-ver-oferta" href="#" target="_blank" rel="noopener noreferrer">Ver oferta</a>
        <a class="btn-compare-outro" href="/" id="btn-comparar-header">
          <span>⇄</span> Comparar com outro tênis
        </a>
        <button class="share-btn" onclick="shareShoe()">↗ Compartilhar</button>
      </div>
    </div>
  </div>

  <!-- DESTAQUES -->
  <h2 class="section-title">Destaques <span class="tag-pill">principais</span></h2>
  <div id="dest-table"></div>

  <!-- PONTUAÇÃO -->
  <h2 class="section-title">Pontuação <span class="tag-pill">avaliação</span></h2>
  <div id="scores-table"></div>

  <!-- FICHA TÉCNICA -->
  <h2 class="section-title">Ficha técnica <span class="tag-pill">completo</span></h2>
  <div id="specs-table"></div>

  <!-- TAMANHO — oculto se sem dados -->
  <div id="sec-fit-wrap" style="display:none">
    <h2 class="section-title">Tamanho e ajuste</h2>
    <div id="fit-table"></div>
  </div>

  <!-- BLOCO FINAL -->
  <div class="page-footer">

    <!-- CTA COMPRA -->
    <div class="cta-section" id="cta-section" style="display:none">
      <div class="cta-img-wrap" id="cta-img"></div>
      <div class="cta-info">
        <div class="cta-brand" id="cta-brand"></div>
        <div class="cta-name" id="cta-name"></div>
        <div class="cta-genero" id="cta-genero"></div>
        <a class="cta-btn" id="cta-link" href="#" target="_blank" rel="noopener noreferrer">Ver melhor oferta</a>
      </div>
    </div>

    <!-- COMPARAR RODAPÉ -->
    <div class="footer-compare">
      <div class="footer-compare-title">Compare antes de decidir</div>
      <div class="footer-compare-sub">Compare com outro modelo e veja as diferenças lado a lado.</div>
      <a class="btn-compare-footer" href="/" id="btn-comparar-footer">
        <span>⇄</span> Comparar com outro tênis
      </a>
    </div>

    <!-- SUGESTÕES -->
    <div class="sugestoes-block" id="sugestoes-block" style="display:none">
      <div class="sugestoes-title">Comparar com</div>
      <div class="sugestoes-list" id="sugestoes-list"></div>
    </div>

    <!-- COMPARTILHAR RODAPÉ -->
    <div class="footer-share">
      <button class="footer-share-btn" onclick="shareShoe()">↗ Compartilhar esta página</button>
    </div>

  </div>

</div>

<script>
const SHOE = <?= json_encode($shoe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SUGESTOES = <?= json_encode(array_values($sugestoes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PLACEHOLDER = '/assets/icons/shoe-placeholder.png';

// ── Segurança ─────────────────────────────────────────────────────────────────
function sanitizeText(v) {
  if (v===null||v===undefined) return '';
  return String(v).trim().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;');
}
function safeUrl(url) {
  if (!url||typeof url!=='string') return null;
  const t=url.trim(); if(!t) return null;
  if (t.startsWith('/')) return t;
  const l=t.toLowerCase();
  if (l.startsWith('javascript:')||l.startsWith('data:')||l.startsWith('vbscript:')||l.startsWith('blob:')||l.startsWith('file:')) return null;
  if (l.startsWith('https://')) return t;
  return null;
}
function applyAffiliateLink(el,url) {
  const safe=safeUrl(url);
  if (!safe){el.removeAttribute('href');el.style.opacity='0.4';el.style.pointerEvents='none';return;}
  el.href=safe;el.target='_blank';el.rel='noopener noreferrer';el.style.opacity='';el.style.pointerEvents='';
}
function set(id,v){const e=document.getElementById(id);if(e)e.textContent=sanitizeText(v);}
function hasVal(v){return v!==null&&v!==undefined&&v!==''&&v!==0&&String(v)!=='0';}

// ── Labels ────────────────────────────────────────────────────────────────────
function lGen(g){return{masculino:'Masculino',feminino:'Feminino',unissex:'Unissex'}[g]||'';}
function lCat(c){return{daily_trainer:'Treino diário',max_cushion:'Máximo amortecimento',performance:'Performance',race:'Corrida / Prova'}[c]||'';}
function lAmort(a){return{maximo:'Máximo',alto:'Alto',medio:'Médio',baixo:'Baixo'}[a]||'';}
function lPerf(p){return{iniciante:'Iniciante',intermediario:'Intermediário',avancado:'Avançado'}[p]||'';}
function lUso(arr){if(!arr||!arr.length)return '—';const m={treino_diario:'Treino diário',longao:'Longão',velocidade:'Velocidade',prova:'Prova',trilha:'Trilha'};return arr.map(t=>m[t]||t).join(' / ');}

// ── DOM helpers ───────────────────────────────────────────────────────────────
function makeSingleRowEl(label,val){
  if(!hasVal(val))return null;
  const row=document.createElement('div');row.className='single-row';
  const lbl=document.createElement('div');lbl.className='single-label';lbl.textContent=label;
  const v=document.createElement('div');v.className='single-value';v.textContent=String(val);
  row.appendChild(lbl);row.appendChild(v);return row;
}
function makeSingleRawRowEl(label,el){
  const row=document.createElement('div');row.className='single-row';
  const lbl=document.createElement('div');lbl.className='single-label';lbl.textContent=label;
  const v=document.createElement('div');v.className='single-value';v.appendChild(el);
  row.appendChild(lbl);row.appendChild(v);return row;
}
function makeScoreSingleRowEl(label,val){
  if(!(val>0))return null;
  const row=document.createElement('div');row.className='score-single-row';
  const lbl=document.createElement('div');lbl.className='score-single-label';lbl.textContent=label;
  const cell=document.createElement('div');cell.className='score-single-cell';
  const num=document.createElement('span');num.className='score-num';num.textContent=val+'/10';
  if(val===10){
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('width','14');svg.setAttribute('height','14');svg.setAttribute('viewBox','0 0 24 24');
    svg.style.cssText='display:inline-block;vertical-align:middle;margin-left:5px;';
    const path=document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('fill','#22c55e');
    path.setAttribute('d','M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z');
    svg.appendChild(path);num.appendChild(svg);
  }
  cell.appendChild(num);
  const blocks=document.createElement('div');blocks.className='score-blocks';
  for(let i=1;i<=10;i++){const b=document.createElement('div');b.className='score-block'+(i<=val?' filled':'');blocks.appendChild(b);}
  cell.appendChild(blocks);
  row.appendChild(lbl);row.appendChild(cell);return row;
}
function makePlacaChipEl(shoe){
  if(!shoe.possui_placa){const s=document.createElement('span');s.textContent='Não';return s;}
  const wrap=document.createElement('span');wrap.className='placa-chip sim';wrap.textContent='Sim ';
  if(shoe.tipo_placa){const t=document.createElement('span');t.className='placa-tipo';t.textContent=shoe.tipo_placa;wrap.appendChild(t);}
  return wrap;
}
function makeWeightBarEl(shoe){
  if(!(shoe.peso_g>0))return null;
  const pct=Math.max(0,Math.min(100,((shoe.peso_g-200)/(320-200))*100));
  const mPct=Math.max(3,Math.min(97,pct));
  const wrap=document.createElement('div');wrap.className='weight-bar-wrap';
  const val=document.createElement('span');val.className='weight-val';val.textContent=shoe.peso_g+'g';
  const track=document.createElement('div');track.className='weight-track';
  const fill=document.createElement('div');fill.className='weight-fill';fill.style.width=pct+'%';
  const mark=document.createElement('div');mark.className='weight-marker';mark.style.left=mPct+'%';
  track.appendChild(fill);track.appendChild(mark);
  const labels=document.createElement('div');labels.className='weight-labels';
  const sL=document.createElement('span');sL.textContent='leve';
  const sR=document.createElement('span');sR.textContent='pesado';
  labels.appendChild(sL);labels.appendChild(sR);
  wrap.appendChild(val);wrap.appendChild(track);wrap.appendChild(labels);
  return wrap;
}

// ── Compartilhar ──────────────────────────────────────────────────────────────
function shareShoe(){
  if(navigator.share){navigator.share({title:document.title,url:window.location.href});}
  else{navigator.clipboard.writeText(window.location.href);alert('Link copiado!');}
}

// ── Render ────────────────────────────────────────────────────────────────────
function render(shoe){
  // ── Item 2: subtítulo dinâmico ──
  const catSubMap = {
    daily_trainer: 'tênis de corrida versátil',
    max_cushion: 'tênis com máximo amortecimento',
    performance: 'tênis leve e responsivo',
    race: 'tênis de alta performance para corrida'
  };
  const usoSubMap = {
    treino_diario:'treinos diários', longao:'longões',
    velocidade:'treinos rápidos', prova:'provas', trilha:'trilhas'
  };
  const catStr = catSubMap[shoe.categoria] || lCat(shoe.categoria);
  const usoArr = (shoe.tipos_uso||[]).slice(0,2).map(u=>usoSubMap[u]||u).filter(Boolean);
  const subtitle = usoArr.length
    ? catStr + ' ideal para ' + usoArr.join(' e ')
    : catStr;
  document.getElementById('page-subtitle').textContent = subtitle;

  // ── Item 3: imagem ──
  const imgWrap=document.getElementById('shoe-img-wrap');
  function appendPlaceholder(el){
    const w=document.createElement('div');w.className='shoe-placeholder-wrap';
    const ph=document.createElement('img');ph.src=PLACEHOLDER;ph.alt='';
    ph.onerror=function(){this.style.display='none';};
    const txt=document.createElement('span');txt.textContent='imagem em breve';
    w.appendChild(ph);w.appendChild(txt);el.appendChild(w);
  }
  const imgUrl=safeUrl(shoe.imagem);
  if(imgUrl){
    const img=document.createElement('img');img.className='shoe-photo';img.loading='lazy';
    img.src=imgUrl;img.alt=shoe.marca+' '+shoe.modelo+' '+lGen(shoe.genero);
    img.onerror=function(){imgWrap.textContent='';appendPlaceholder(imgWrap);};
    imgWrap.appendChild(img);
  } else { appendPlaceholder(imgWrap); }

  // ── Meta básico ──
  set('meta-brand',shoe.marca);set('meta-name',shoe.modelo);set('meta-genero',lGen(shoe.genero));

  // ── Item 4: tags — categoria, drop, peso ──
  const tagsEl=document.getElementById('meta-tags');
  const tagDefs = [
    lCat(shoe.categoria),
    shoe.drop_mm>0 ? 'Drop '+shoe.drop_mm+'mm' : null,
    shoe.peso_g>0  ? shoe.peso_g+'g'            : null
  ];
  tagDefs.filter(Boolean).forEach(t=>{
    const tag=document.createElement('span');tag.className='shoe-tag';tag.textContent=t;tagsEl.appendChild(tag);
  });

  // ── Item 5: nota com label ──
  const notaEl=document.getElementById('meta-nota');
  if(shoe.nota_geral>0){
    const notaLabels = [[9,'excelente'],[7,'boa opção'],[5,'na média'],[0,'abaixo da média']];
    const notaLbl = (notaLabels.find(([min])=>shoe.nota_geral>=min)||[0,''])[1];
    const badge=document.createElement('div');badge.className='nota-badge';
    badge.textContent=shoe.nota_geral+'/10';
    const lbl=document.createElement('div');lbl.className='nota-label';
    const strong=document.createElement('strong');strong.textContent=shoe.nota_geral+'/10 · '+notaLbl;
    const span=document.createElement('span');span.textContent='avaliação técnica';
    lbl.appendChild(strong);lbl.appendChild(span);
    notaEl.appendChild(badge);notaEl.appendChild(lbl);
  }

  // ── Item 6: botão Ver oferta sempre visível ──
  const btnOferta = document.getElementById('btn-ver-oferta');
  const ofertaUrl = safeUrl(shoe.afiliado);
  if(ofertaUrl){
    btnOferta.href=ofertaUrl;
    btnOferta.target='_blank';
    btnOferta.rel='noopener noreferrer';
  } else {
    btnOferta.removeAttribute('href');
    btnOferta.classList.add('disabled');
  }

  // Botão comparar
  const compareUrl='/?c1='+encodeURIComponent(shoe.id);
  document.getElementById('btn-comparar-header').href=compareUrl;
  document.getElementById('btn-comparar-footer').href=compareUrl;

  // ── Destaques ──
  const destEl=document.getElementById('dest-table');
  function appendD(label,val){const r=makeSingleRowEl(label,val);if(r)destEl.appendChild(r);}
  appendD('Categoria',lCat(shoe.categoria));
  appendD('Tipos de uso',lUso(shoe.tipos_uso));
  appendD('Amortecimento',lAmort(shoe.amortecimento_nivel));
  const wEl=makeWeightBarEl(shoe);if(wEl)destEl.appendChild(makeSingleRawRowEl('Peso',wEl));
  destEl.appendChild(makeSingleRawRowEl('Placa',makePlacaChipEl(shoe)));
  if(shoe.drop_mm>0) appendD('Drop',shoe.drop_mm+'mm');
  if(hasVal(shoe.recomendacao_tamanho)) appendD('Tamanho',String(shoe.recomendacao_tamanho).toLowerCase().includes('maior')?'Recomendado 1 número maior':shoe.recomendacao_tamanho);
  if(shoe.stack_calcanhar_mm>0&&shoe.stack_antepe_mm>0&&shoe.drop_mm>0){
    const soleRow=document.createElement('div');soleRow.className='single-row';
    const soleLbl=document.createElement('div');soleLbl.className='single-label';soleLbl.textContent='Solado';
    const soleVal=document.createElement('div');soleVal.className='sole-single-wrap';
    const maxSt=Math.max(shoe.stack_calcanhar_mm,shoe.stack_antepe_mm);
    const sc=maxSt>0?58/maxSt:1;
    const hPx=shoe.stack_calcanhar_mm*sc,tPx=shoe.stack_antepe_mm*sc;
    const W=220,H=90,pL=4,pR=4,pB=8,bY=H-pB,x1=pL,x2=W-pR,y1=bY-hPx,y2=bY-tPx,mX=(x1+x2)/2;
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 '+W+' '+H);
    const poly=document.createElementNS('http://www.w3.org/2000/svg','polygon');
    poly.setAttribute('points',x1+','+bY+' '+x2+','+bY+' '+x2+','+y2+' '+x1+','+y1);
    poly.setAttribute('fill','#FFFAF3');poly.setAttribute('stroke','#E8D3A5');
    poly.setAttribute('stroke-width','1.5');poly.setAttribute('stroke-linejoin','round');
    svg.appendChild(poly);
    const txt=document.createElementNS('http://www.w3.org/2000/svg','text');
    txt.setAttribute('x',String(mX));txt.setAttribute('y',String(Math.min(y1,y2)-8));
    txt.setAttribute('font-family','Barlow,sans-serif');txt.setAttribute('font-size','9');
    txt.setAttribute('fill','#888');txt.setAttribute('text-anchor','middle');
    txt.textContent='drop '+Math.round(shoe.drop_mm)+'mm';
    svg.appendChild(txt);soleVal.appendChild(svg);
    const nums=document.createElement('div');nums.className='sole-nums';
    const s1=document.createElement('strong');s1.textContent=shoe.stack_calcanhar_mm+'mm';
    const s2=document.createElement('strong');s2.textContent=shoe.stack_antepe_mm+'mm';
    nums.appendChild(s1);nums.appendChild(document.createTextNode(' calcanhar  ·  '));
    nums.appendChild(s2);nums.appendChild(document.createTextNode(' antépé'));
    soleVal.appendChild(nums);soleRow.appendChild(soleLbl);soleRow.appendChild(soleVal);destEl.appendChild(soleRow);
  }

  // ── Pontuação ──
  const scoresEl=document.getElementById('scores-table');
  [["Nota geral",shoe.nota_geral],["Amortecimento",shoe.nota_amortecimento],["Leveza",shoe.nota_leveza],
   ["Retorno de energia",shoe.nota_resposta],["Custo-benéficio",shoe.nota_custo_beneficio],
   ["Estabilidade",shoe.nota_estabilidade],["Durabilidade",shoe.nota_durabilidade]]
    .forEach(([l,v])=>{const r=makeScoreSingleRowEl(l,v);if(r)scoresEl.appendChild(r);});

  // ── Item 21: Ficha técnica — cabedal e solado no final ──
  const specsEl=document.getElementById('specs-table');
  function appendS(label,val){const r=makeSingleRowEl(label,val);if(r)specsEl.appendChild(r);}
  appendS('Marca',shoe.marca);appendS('Modelo',shoe.modelo);appendS('Gênero',lGen(shoe.genero));
  appendS('Ano de lançamento',shoe.ano_lancamento);appendS('Perfil do corredor',lPerf(shoe.perfil_corredor));
  appendS('Categoria',lCat(shoe.categoria));appendS('Tipos de uso',lUso(shoe.tipos_uso));
  if(shoe.peso_g>0)appendS('Peso',shoe.peso_g+'g');
  appendS('Amortecimento',lAmort(shoe.amortecimento_nivel));appendS('Espuma',shoe.espuma);
  if(shoe.drop_mm>0)appendS('Drop',shoe.drop_mm+'mm');
  if(shoe.stack_calcanhar_mm>0)appendS('Altura no calcanhar',shoe.stack_calcanhar_mm+'mm');
  if(shoe.stack_antepe_mm>0)appendS('Altura no antepé',shoe.stack_antepe_mm+'mm');
  specsEl.appendChild(makeSingleRawRowEl('Placa',makePlacaChipEl(shoe)));
  if(shoe.possui_placa&&shoe.tipo_placa)appendS('Tipo de placa',shoe.tipo_placa);
  appendS('Estabilidade',shoe.estabilidade_tipo);
  appendS('Cabedal',shoe.cabedal);
  appendS('Solado',shoe.solado);

  // ── Tamanho ──
  const hasFit=hasVal(shoe.recomendacao_tamanho)||hasVal(shoe.observacao_tamanho);
  if(hasFit){
    const fitEl=document.getElementById('fit-table');
    [["Recomendação",shoe.recomendacao_tamanho],["Observação",shoe.observacao_tamanho]]
      .forEach(([l,v])=>{const r=makeSingleRowEl(l,v);if(r)fitEl.appendChild(r);});
    document.getElementById('sec-fit-wrap').style.display='block';
  }

  // ── CTA compra sempre exibido ──
  set('cta-brand',shoe.marca);set('cta-name',shoe.modelo);set('cta-genero',lGen(shoe.genero));
  const ctaImgWrap=document.getElementById('cta-img');
  const ctaImgUrl=safeUrl(shoe.imagem);
  if(ctaImgUrl){
    const img=document.createElement('img');img.className='shoe-photo';img.loading='lazy';
    img.src=ctaImgUrl;img.alt=shoe.marca+' '+shoe.modelo;
    img.onerror=function(){ctaImgWrap.textContent='';const ph=document.createElement('img');ph.className='shoe-placeholder';ph.src=PLACEHOLDER;ph.alt='';ctaImgWrap.appendChild(ph);};
    ctaImgWrap.appendChild(img);
  } else {
    const ph=document.createElement('img');ph.className='shoe-placeholder';ph.src=PLACEHOLDER;ph.alt='';ctaImgWrap.appendChild(ph);
  }
  applyAffiliateLink(document.getElementById('cta-link'),shoe.afiliado);
  document.getElementById('cta-section').style.display='flex';

  // ── Sugestões — card clicável + botão oferta ──
  if(SUGESTOES.length>0){
    const list=document.getElementById('sugestoes-list');
    SUGESTOES.forEach(s=>{
      const item=document.createElement('div');
      item.className='sugestao-item';
      item.onclick=()=>{ window.location.href='/?c1='+encodeURIComponent(shoe.id)+'&c2='+encodeURIComponent(s.id); };
      const info=document.createElement('div');info.className='sugestao-info';
      const brand=document.createElement('div');brand.className='sugestao-brand';brand.textContent=s.marca;
      const name=document.createElement('div');name.className='sugestao-name';name.textContent=s.modelo;
      const meta=document.createElement('div');meta.className='sugestao-meta';
      const mp=[lGen(s.genero),lCat(s.categoria)];
      if(s.nota_geral>0)mp.push('Nota '+s.nota_geral+'/10');
      meta.textContent=mp.filter(Boolean).join(' · ');
      info.appendChild(brand);info.appendChild(name);info.appendChild(meta);
      const right=document.createElement('div');right.className='sugestao-right';
      const ofBtn=document.createElement('a');ofBtn.className='sugestao-oferta';ofBtn.textContent='Ver oferta';
      const ofUrl=safeUrl(s.afiliado);
      if(ofUrl){ofBtn.href=ofUrl;ofBtn.target='_blank';ofBtn.rel='noopener noreferrer';ofBtn.onclick=e=>e.stopPropagation();}
      else{ofBtn.classList.add('disabled');ofBtn.onclick=e=>e.stopPropagation();}
      const arrow=document.createElement('span');arrow.className='sugestao-arrow';arrow.textContent='→';
      right.appendChild(ofBtn);right.appendChild(arrow);
      item.appendChild(info);item.appendChild(right);
      list.appendChild(item);
    });
    document.getElementById('sugestoes-block').style.display='block';
  }

  // ── Schema JSON-LD ──
  const schema={"@context":"https://schema.org","@type":"Product",
    "name":shoe.marca+' '+shoe.modelo,
    "brand":{"@type":"Brand","name":shoe.marca},
    "description":document.querySelector('meta[name="description"]').content,
    "image":safeUrl(shoe.imagem)||''
  };
  if(shoe.nota_geral>0) schema.aggregateRating={"@type":"AggregateRating","ratingValue":shoe.nota_geral,"bestRating":"10","ratingCount":"1"};
  document.getElementById('schema-jsonld').textContent=JSON.stringify(schema);
}
render(SHOE);
</script>
</body>
</html>
