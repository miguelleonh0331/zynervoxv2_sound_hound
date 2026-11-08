<?php
declare(strict_types=1);
// Guía interactiva: recorre las pantallas reales con un puntero animado y textos en español llano, en MODO DEMOSTRACIÓN.
// Esta página no escribe nada ni hace consultas: solo sirve la guía (ver vendor/guia/guia.js para el aislamiento del iframe).
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;

Auth::checkAccess(9);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guía - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .g-demo { display: flex; flex-wrap: wrap; gap: 0.3rem 1rem; align-items: center; margin: 0 0 0.75rem; padding: 0.4rem 0.7rem; border: 1px solid #f59e0b; border-left: 5px solid #f59e0b; background: #fffbeb; color: #78350f; font-size: 0.8rem; }
        .g-demo b { text-transform: uppercase; letter-spacing: 0.04em; } .g-demo .n { margin-left: auto; color: #92400e; }
        .g-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.75rem; }
        .g-topic { background: var(--bg-card); border: 1px solid var(--border); border-radius: 3px; padding: 0.8rem; display: flex; flex-direction: column; gap: 0.4rem; }
        .g-topic h3 { margin: 0; font-size: 1rem; } .g-topic p { margin: 0; color: var(--text-muted); font-size: 0.82rem; flex: 1; } .g-ico { font-size: 1.6rem; color: var(--primary); }
        .g-topic.soon { opacity: 0.6; }
        .g-btn { padding: 4px 12px; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); color: var(--text); cursor: pointer; font-size: 0.8rem; }
        .g-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); } .g-btn:disabled { opacity: .45; cursor: not-allowed; }
        .g-primary { background: var(--primary); border-color: var(--primary); color: #fff; } .g-primary:hover:not(:disabled) { color: #fff; filter: brightness(1.08); }
        .g-bar { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.4rem; flex-wrap: wrap; } .g-bar h2 { margin: 0; font-size: 1rem; }
        #dots { display: flex; gap: 4px; flex-wrap: wrap; } .g-step-dot { width: 10px; height: 10px; border-radius: 50%; background: #d1d5db; cursor: pointer; } .g-step-dot.on { background: var(--primary); }
        #stage { position: relative; height: calc(100vh - 190px); min-height: 460px; border: 2px solid #1f2937; background: #fff; overflow: hidden; }
        #frame { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #f4efe8; }
        #stage.loading::after { content: 'Cargando pantalla…'; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.8); font-weight: 700; }
        #hl { position: absolute; left: 0; top: 0; width: 0; height: 0; border: 3px solid var(--primary); border-radius: 4px; box-shadow: 0 0 0 9999px rgba(17,24,39,.35); pointer-events: none; opacity: 0; transition: transform .6s ease, width .6s ease, height .6s ease, opacity .3s; }
        #ptr { position: absolute; left: 0; top: 0; width: 28px; height: 28px; margin: -3px 0 0 -3px; pointer-events: none; opacity: 0; transition: transform .9s cubic-bezier(.45,.05,.25,1), opacity .3s; filter: drop-shadow(0 2px 3px rgba(0,0,0,.5)); z-index: 5; }
        #gpanel { position: fixed; z-index: 1000; box-sizing: border-box; background: #111827; color: #f9fafb; border: 1px solid #374151; border-radius: 6px; padding: 0.6rem 0.7rem; box-shadow: 0 8px 24px rgba(0,0,0,.45); font-size: 0.82rem; line-height: 1.4; display: flex; flex-direction: column; gap: 0.4rem; overflow: hidden; }
        #gpanel[hidden] { display: none; } .g-tip-h { display: flex; flex-direction: column; gap: 0.1rem; } .g-count { color: #9ca3af; font-size: 0.74rem; }
        .g-tip-t { margin: 0; flex: 1; min-height: 0; overflow-y: auto; } .g-tip-n { margin: 0; color: #fbbf24; font-size: 0.74rem; }
        .g-tip-b { display: flex; gap: 0.3rem; flex-wrap: wrap; } #gpanel .g-btn { background: #1f2937; color: #f9fafb; border-color: #374151; padding: 3px 8px; } #gpanel .g-primary { background: var(--primary); border-color: var(--primary); }
        .g-ripple { position: absolute; width: 14px; height: 14px; margin: -7px 0 0 -7px; border-radius: 50%; border: 3px solid var(--primary); pointer-events: none; z-index: 7; animation: ripple .65s ease-out forwards; }
        @keyframes ripple { to { transform: scale(4); opacity: 0; } }
        .g-sim { position: absolute; z-index: 8; background: #b91c1c; color: #fff; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.03em; padding: 3px 8px; border-radius: 3px; pointer-events: none; box-shadow: 0 2px 8px rgba(0,0,0,.4); }
        @media (prefers-reduced-motion: reduce) { #hl, #ptr { transition: none; } .g-ripple { animation: none; opacity: 0; } }
    </style>
</head>
<body>
    <?php renderSidebar('guia'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.75rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Guía</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Aprende cómo funciona el sistema: un puntero te enseña cada pantalla paso a paso, con datos de ejemplo.</p>
            </div>
        </header>

        <div class="g-demo"><b>Modo demostración</b><span>Todo es un ensayo con datos de ejemplo: no se guarda, no se inicia ni se detiene nada y no se llama a nadie.</span><span class="n">Escrituras bloqueadas: <b id="blocked">0</b></span></div>

        <section id="index">
            <h2 style="font-size: 1rem; margin: 0 0 0.5rem;">Elige un tema</h2>
            <div class="g-grid" id="topics"></div>
        </section>

        <section id="tour" hidden>
            <div class="g-bar"><h2 id="tour-title"></h2><div id="dots"></div></div>
            <div id="stage">
                <iframe id="frame" title="Pantalla de ejemplo" sandbox="allow-same-origin allow-scripts"></iframe>
                <div id="hl"></div>
                <div id="ptr"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M3 2l7 19 3-8 8-3z" fill="#fff" stroke="#111827" stroke-width="1.6" stroke-linejoin="round"/></svg></div>
            </div>
        </section>
    </main>
    <div id="gpanel" hidden role="region" aria-label="Panel de la guía"></div>
    <script src="vendor/guia/guia_data.js"></script>
    <script src="vendor/guia/guia.js"></script>
</body>
</html>
