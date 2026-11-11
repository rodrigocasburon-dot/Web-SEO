# Pendientes de los perfiles de lectura

Estado a 11/11/2026. Lo comprueba `tests/perfiles_resumen.php` (caso 7) con `Perfil::missing()`.

## Textos que faltan

Ninguno. Las 26 comprobaciones del catálogo (C01–C31) y los dos puntos de «Toda la web»
(`WEB-TITULOS`, `WEB-404`) tienen texto para los tres perfiles:

| Perfil | Campos obligatorios | Origen |
|---|---|---|
| Marketing | significa, importa, hacer | `lang/perfiles.php` |
| Dirección | significa, importa, hacer | `lang/perfiles.php` |
| Desarrollo web | importa, comprobar | `lang/perfiles.php` (significa y hacer salen de `lang/es.php`) |

Si se añade una comprobación nueva y le falta algún texto, la pantalla usa el texto general
(`lang/es.php`) y el punto se marca como «texto de perfil pendiente»; la prueba falla hasta que se complete.

## Pendiente de validar por el grupo

Los textos de perfil son una primera redacción propia, no revisada por marketing ni por dirección:

1. Tono y vocabulario de los textos de Marketing y Dirección (`lang/perfiles.php`).
2. Relevancia por perfil (`config/perfiles.php`, `relevancia`): solo desempata puntos del mismo peso;
   no cambia notas, estados ni pesos.
3. Explicación del respaldo de los puntos GEO (oficial / experimento / hipótesis) en cada perfil.
4. Perfil por defecto: `desarrollo`.
5. Umbrales de los rangos: 0–49 Crítico, 50–74 Mejorable, 75–89 Bueno, 90–100 Muy bueno.
