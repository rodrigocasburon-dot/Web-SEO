<?php
// Bots que se comprueban en robots.txt, por categoría (C02 y tabla informativa de bots). Sin secretos.
// Fuente: documentación pública de cada proveedor (OpenAI, Anthropic, Perplexity, Google, Microsoft, Apple,
// Common Crawl). Revisar cuando cambien. Los tokens se comparan sin distinguir mayúsculas (RFC 9309).
//
// - entrenamiento: recopilan contenido para entrenar modelos. Bloquearlos es una decisión de política de
//   contenidos y no afecta a la búsqueda: nunca penaliza.
// - busqueda: indexan para mostrar la página en resultados y respuestas. «penaliza» => true: si se bloquea,
//   C02 es NO_CUMPLE. Applebot no penaliza (solo aviso) porque no se ha acordado en el grupo.
// - usuario: agentes que visitan la página cuando una persona lo pide. Solo informativos: robots.txt
//   puede no aplicarse a ellos.
// «hereda»: si robots.txt no tiene un grupo propio para el bot, se aplican las reglas de ese otro bot
// (Apple documenta que Applebot sigue las reglas de Googlebot cuando no hay reglas para Applebot).
return [
    'entrenamiento' => [
        ['bot' => 'GPTBot', 'proveedor' => 'OpenAI'],
        ['bot' => 'ClaudeBot', 'proveedor' => 'Anthropic'],
        ['bot' => 'Google-Extended', 'proveedor' => 'Google'],
        ['bot' => 'Applebot-Extended', 'proveedor' => 'Apple'],
        ['bot' => 'CCBot', 'proveedor' => 'Common Crawl'],
    ],
    'busqueda' => [
        ['bot' => 'OAI-SearchBot', 'proveedor' => 'OpenAI', 'penaliza' => true],
        ['bot' => 'Claude-SearchBot', 'proveedor' => 'Anthropic', 'penaliza' => true],
        ['bot' => 'PerplexityBot', 'proveedor' => 'Perplexity', 'penaliza' => true],
        ['bot' => 'Googlebot', 'proveedor' => 'Google', 'penaliza' => true],
        ['bot' => 'Bingbot', 'proveedor' => 'Microsoft', 'penaliza' => true],
        ['bot' => 'Applebot', 'proveedor' => 'Apple', 'penaliza' => false, 'hereda' => 'Googlebot'],
    ],
    'usuario' => [
        ['bot' => 'ChatGPT-User', 'proveedor' => 'OpenAI'],
        ['bot' => 'Claude-User', 'proveedor' => 'Anthropic'],
        ['bot' => 'Perplexity-User', 'proveedor' => 'Perplexity'],
        // [SUPUESTO] token indicado por el grupo; no se ha podido confirmar en la documentación de Google.
        ['bot' => 'Google-Agent', 'proveedor' => 'Google'],
    ],
];
