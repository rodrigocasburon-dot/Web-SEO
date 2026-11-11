<?php
// v3.0: copiado de la v1.0 (lib/navegador.php). Controla Chrome sin Composer por el protocolo DevTools.
// Las funciones ruta_chrome() y borrar_carpeta() se cargan junto con la clase.
// Controla un Chrome sin ventana desde PHP, hablando con él por el protocolo de DevTools.
//
// Seguridad (ver docs/SEGURIDAD.md). Chrome resuelve los nombres por su cuenta, DESPUÉS de que UrlGuard los
// haya validado: un DNS malicioso puede contestar una IP pública a PHP y una privada a Chrome («DNS
// rebinding»). Tres medidas, las tres en código y sin infraestructura:
//  1. Chrome NO resuelve nombres por su cuenta ('solo_fijados'): se arranca con --host-resolver-rules, que
//     fija cada host permitido a la IP que validó UrlGuard y hace fallar la resolución de todos los demás
//     («MAP host IP, …, MAP * ~NOTFOUND»). Como las reglas solo se pueden dar al arrancar, BrowserChecks
//     carga la página hasta cuatro veces: en cada pasada anota los hosts nuevos que la página pide (ya
//     validados) y los fija en la siguiente. Un host que aparezca después no se puede resolver: falla.
//  2. Cada petición (también redirecciones y subrecursos) se valida con UrlGuard antes de salir. La caché de
//     esa validación va por esquema, host y puerto (antes solo por host: un puerto no permitido se colaba).
//  3. Defensa adicional: al llegar cada respuesta, si Chrome informa de que se conectó a una red que no es
//     pública (resourceIPAddressSpace), la respuesta se descarta antes de entregarla a la página.
// Lo que esto NO cubre (requisitos de despliegue, no de código): que la IP validada sea pública pero lleve a
// un servicio interno por la red del servidor (NAT, proxy transparente), fallos del propio Chrome o de su
// aislamiento, y el consumo de recursos. Para eso hacen falta filtrado de salida y aislamiento de red.

class Navegador
{
    /** Redes a las que Chrome puede haberse conectado para que la respuesta se entregue a la página */
    private const ESPACIOS_PERMITIDOS = ['Public'];
    private const PREFIJO_PERFIL = 'web_seo_chrome_';

    private $proceso;
    private $socket;
    private string $perfil;
    private string $buffer = '';
    private int $siguiente_id = 0;
    private array $cola = [];
    private array $hosts = [];
    private array $fijados = [];
    /** Red real de cada petición (id de red → Public, Private, Local, Loopback…), según Chrome */
    private array $espacios = [];
    /** Hosts validados por UrlGuard en esta sesión (host → IP validada), fijados o no */
    private array $validados = [];
    /** Máximo de hosts que se fijan en una sesión (el valor va en la línea de órdenes de Chrome) */
    public const MAX_FIJADOS = 60;
    private UrlGuard $guard;
    private float $limite_total = 0.0;
    private int $peticiones = 0;
    private int $max_peticiones = 1500;
    private bool $cerrado = false;
    /** Recuento para el informe: peticiones rechazadas antes de salir y respuestas descartadas */
    public array $seguridad = ['peticiones' => 0, 'rechazadas' => 0, 'respuestas_descartadas' => 0];

    /**
     * @param array $config chrome_ruta, chrome_sin_sandbox; opcionales: 'fijar' => [host => IP validada],
     *                      'solo_fijados' (true: Chrome no puede resolver ningún otro host),
     *                      'max_segundos' (tiempo total de la sesión, 90 por defecto), 'max_peticiones'
     */
    public function __construct(private array $config, ?UrlGuard $guard = null)
    {
        $this->guard = $guard ?? new UrlGuard();
        $ruta = ruta_chrome($config);
        if ($ruta === null) {
            throw new RuntimeException('No se ha encontrado Chrome.');
        }
        // Restos de sesiones interrumpidas (proceso de PHP matado, servidor reiniciado…)
        self::limpiar_huerfanos();

        $fijar = array_slice((array) ($config['fijar'] ?? []), 0, self::MAX_FIJADOS, true);
        $reglas = self::reglas_resolucion($fijar, !empty($config['solo_fijados']));
        $this->fijados = array_change_key_case($fijar, CASE_LOWER);
        $this->limite_total = microtime(true) + max(10, (int) ($config['max_segundos'] ?? 90));
        $this->max_peticiones = max(1, (int) ($config['max_peticiones'] ?? 1500));

        $this->perfil = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::PREFIJO_PERFIL . bin2hex(random_bytes(6));
        mkdir($this->perfil);
        // Pase lo que pase (exit, error fatal, tiempo agotado, excepción no capturada), Chrome y su perfil se
        // cierran al terminar la petición. cerrar() se puede llamar varias veces.
        register_shutdown_function([$this, 'cerrar']);

        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open([$ruta, '--headless=new', '--remote-debugging-port=0', "--user-data-dir={$this->perfil}",
            '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-sync',
            '--disable-background-networking', '--disable-component-update', '--mute-audio', '--window-size=1280,800',
            ...($reglas !== '' ? ['--host-resolver-rules=' . $reglas] : []),
            ...($config['chrome_sin_sandbox'] ? ['--no-sandbox'] : []), 'about:blank'],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $tuberias, null, null, ['bypass_shell' => true]);
        if (!is_resource($this->proceso)) {
            $this->cerrar();
            throw new RuntimeException('No se ha podido abrir Chrome.');
        }

        // Si algo falla al preparar Chrome, no debe quedar abierto.
        try {
            $this->preparar();
        } catch (Throwable $e) {
            $this->cerrar();
            throw $e;
        }
    }

    public function __destruct()
    {
        $this->cerrar();
    }

    /**
     * Reglas para --host-resolver-rules que fijan cada host a su IP validada: «MAP host IP, MAP host2 [IPv6]».
     * Con $solo_fijados se añade al final «MAP * ~NOTFOUND»: la primera regla que coincide gana, así que los
     * hosts fijados se resuelven a su IP y cualquier otro nombre no se puede resolver.
     * Los hosts o IP con caracteres inesperados se descartan (el valor va a la línea de órdenes de Chrome).
     */
    public static function reglas_resolucion(array $fijar, bool $solo_fijados = false): string
    {
        $reglas = [];
        foreach ($fijar as $host => $ip) {
            $host = strtolower((string) $host);
            if (!preg_match('/^[a-z0-9._-]{1,253}$/', $host) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $reglas[] = 'MAP ' . $host . ' ' . (strpos((string) $ip, ':') !== false ? '[' . $ip . ']' : $ip);
        }
        if ($solo_fijados) {
            $reglas[] = 'MAP * ~NOTFOUND';
        }
        return implode(', ', $reglas);
    }

    /**
     * Hosts que la página ha pedido, que UrlGuard ha validado y que no estaban fijados (host → IP validada).
     * Con 'solo_fijados' esas peticiones han fallado; fijándolos en una sesión nueva, cargarán.
     */
    public function sin_fijar(): array
    {
        return array_diff_key($this->validados, $this->fijados);
    }

    /**
     * ¿Se entrega la respuesta a la página? $espacio es la red a la que Chrome se conectó de verdad
     * (null si Chrome no lo ha dicho). Sin ese dato, se entrega solo si el host está fijado a su IP validada
     * (o es una IP literal ya validada); sin dato y sin fijar, se descarta (ante la duda, no).
     */
    public static function respuesta_permitida(?string $espacio, bool $fijado): bool
    {
        if ($espacio !== null) {
            return in_array($espacio, self::ESPACIOS_PERMITIDOS, true);
        }
        return $fijado;
    }

    /**
     * Borra perfiles temporales de Chrome abandonados (más de $segundos sin terminar) y mata el Chrome que
     * los use. El proceso se busca por la ruta del perfil, que lleva un nombre aleatorio: no se puede matar
     * por error otro programa. La llaman el constructor y bin/limpiar.php.
     * @return int perfiles borrados
     */
    public static function limpiar_huerfanos(int $segundos = 900, ?string $base = null): int
    {
        $base = $base ?? sys_get_temp_dir();
        $borrados = 0;
        foreach (glob($base . DIRECTORY_SEPARATOR . self::PREFIJO_PERFIL . '*', GLOB_ONLYDIR) ?: [] as $carpeta) {
            $nombre = basename($carpeta);
            if (!preg_match('/^' . self::PREFIJO_PERFIL . '[0-9a-f]{12}$/', $nombre) || (int) @filemtime($carpeta) > time() - $segundos) {
                continue;
            }
            self::matar_por_perfil($nombre);
            for ($i = 0; $i < 5 && is_dir($carpeta); $i++) {
                borrar_carpeta($carpeta);
                if (is_dir($carpeta)) {
                    usleep(200000);
                }
            }
            if (!is_dir($carpeta)) {
                $borrados++;
            }
        }
        return $borrados;
    }

    /** Mata los procesos cuya línea de órdenes contiene el nombre (aleatorio) del perfil. */
    private static function matar_por_perfil(string $nombre): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $nombre) || !function_exists('exec')) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // -EncodedCommand evita problemas de comillas y que PowerShell se encuentre a sí mismo en la búsqueda
            $script = 'Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*' . $nombre . '*" -and $_.ProcessId -ne $PID }'
                . ' | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }';
            @exec('powershell -NoProfile -NonInteractive -EncodedCommand ' . base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')) . ' 2>NUL');
        } else {
            @exec('pkill -9 -f ' . escapeshellarg('user-data-dir=.*' . $nombre) . ' 2>/dev/null');
        }
    }

    private function preparar(): void
    {
        // Chrome elige un puerto libre y lo escribe en este archivo.
        $archivo = $this->perfil . DIRECTORY_SEPARATOR . 'DevToolsActivePort';
        $limite = microtime(true) + 15;
        while (!is_file($archivo) || filesize($archivo) === 0) {
            if (microtime(true) > $limite) {
                throw new RuntimeException('Chrome no ha arrancado.');
            }
            usleep(100000);
        }
        $puerto = (int) file($archivo)[0];

        $c = curl_init("http://127.0.0.1:$puerto/json/new?about:blank");
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $pestana = json_decode((string) curl_exec($c), true);
        curl_close($c);
        if (!isset($pestana['webSocketDebuggerUrl'])) {
            throw new RuntimeException('Chrome no ha abierto la pestaña.');
        }
        $this->conectar($puerto, parse_url($pestana['webSocketDebuggerUrl'], PHP_URL_PATH));

        $this->enviar('Page.enable');
        $this->enviar('Runtime.enable');
        // Toda petición pasa por aquí dos veces: antes de salir (se bloquea lo interno y cualquier envío de
        // datos) y al llegar la respuesta (se descarta si Chrome se conectó a una red que no es pública).
        // Network.enable va antes: da la red real de cada respuesta (responseReceivedExtraInfo).
        $this->enviar('Network.enable');
        $this->enviar('Fetch.enable', ['patterns' => [
            ['urlPattern' => '*', 'requestStage' => 'Request'],
            ['urlPattern' => '*', 'requestStage' => 'Response'],
        ]]);
        $this->enviar('Network.setBlockedURLs', ['urls' => ['ws://*', 'wss://*']]);
        // La página no puede guardar archivos en el servidor
        try {
            $this->enviar('Browser.setDownloadBehavior', ['behavior' => 'deny']);
        } catch (Throwable $e) {
            // Versiones de Chrome sin esta orden: las descargas quedarían en el perfil temporal, que se borra
        }
    }

    public function ir(string $url, int $segundos = 15): void
    {
        unset($this->cola['Page.loadEventFired']);
        $this->enviar('Page.navigate', ['url' => $url]);
        $limite = microtime(true) + $segundos;
        while (!isset($this->cola['Page.loadEventFired']) && microtime(true) < $limite) {
            $this->leer(0.2);
        }
        $this->pausa(1500);
    }

    // Ejecuta JavaScript en la página y devuelve su valor.
    public function evaluar(string $codigo): mixed
    {
        $r = $this->enviar('Runtime.evaluate', ['expression' => $codigo, 'returnByValue' => true, 'awaitPromise' => true]);
        if (isset($r['exceptionDetails'])) {
            throw new RuntimeException('Error al medir la página: ' . ($r['exceptionDetails']['exception']['description'] ?? 'desconocido'));
        }
        return $r['result']['value'] ?? null;
    }

    public function tecla_tab(): void
    {
        $tecla = ['key' => 'Tab', 'code' => 'Tab', 'windowsVirtualKeyCode' => 9, 'nativeVirtualKeyCode' => 9];
        $this->enviar('Input.dispatchKeyEvent', ['type' => 'keyDown'] + $tecla);
        $this->enviar('Input.dispatchKeyEvent', ['type' => 'keyUp'] + $tecla);
    }

    // Algunas webs no enseñan el aviso de cookies hasta que hay actividad.
    public function mover_raton(): void
    {
        foreach ([[200, 200], [240, 260]] as [$x, $y]) {
            $this->enviar('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $x, 'y' => $y]);
        }
        $this->tecla_tab();
    }

    public function tamano(?int $ancho, int $alto = 800): void
    {
        $ancho === null
            ? $this->enviar('Emulation.clearDeviceMetricsOverride')
            : $this->enviar('Emulation.setDeviceMetricsOverride', ['width' => $ancho, 'height' => $alto, 'deviceScaleFactor' => 1, 'mobile' => false]);
        $this->pausa(500);
    }

    // Espera sin dejar de atender las peticiones de la página.
    public function pausa(int $ms): void
    {
        $limite = microtime(true) + $ms / 1000;
        while (($resto = $limite - microtime(true)) > 0) {
            $this->leer(min($resto, 0.1));
        }
    }

    /** Cierra Chrome y borra su perfil. Se puede llamar varias veces (finally, destructor y fin de la petición). */
    public function cerrar(): void
    {
        if ($this->cerrado) {
            return;
        }
        $this->cerrado = true;
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        if (is_resource($this->proceso)) {
            $pid = (int) (proc_get_status($this->proceso)['pid'] ?? 0);
            if (PHP_OS_FAMILY === 'Windows') {
                if ($pid > 0) {
                    @exec("taskkill /F /T /PID $pid 2>NUL");
                }
            } else {
                proc_terminate($this->proceso, 9);
            }
            proc_close($this->proceso);
        }
        if (!isset($this->perfil)) {
            return;
        }
        // Chrome tarda un momento en soltar sus archivos.
        for ($i = 0; $i < 10 && is_dir($this->perfil); $i++) {
            borrar_carpeta($this->perfil);
            if (is_dir($this->perfil)) {
                usleep(200000);
            }
        }
        if (is_dir($this->perfil)) {
            // Queda algún proceso hijo con archivos abiertos: se busca por el nombre del perfil
            self::matar_por_perfil(basename($this->perfil));
            usleep(300000);
            borrar_carpeta($this->perfil);
        }
    }

    /** Tiempo total de la sesión: evita que un Chrome colgado retenga la petición (en Linux, max_execution_time no cuenta las esperas). */
    private function comprobar_tiempo(): void
    {
        if ($this->limite_total > 0 && microtime(true) > $this->limite_total) {
            throw new RuntimeException('Las pruebas con Chrome han superado el tiempo máximo.');
        }
    }

    private function enviar(string $metodo, array $parametros = []): array
    {
        $id = ++$this->siguiente_id;
        $this->escribir(json_encode(['id' => $id, 'method' => $metodo, 'params' => (object) $parametros]));
        $limite = microtime(true) + 30;
        while (!isset($this->cola[$id])) {
            if (microtime(true) > $limite) {
                throw new RuntimeException("Chrome no responde a $metodo.");
            }
            $this->comprobar_tiempo();
            $this->leer(0.2);
        }
        $respuesta = $this->cola[$id];
        unset($this->cola[$id]);
        if (isset($respuesta['error'])) {
            throw new RuntimeException("Chrome ha rechazado $metodo: {$respuesta['error']['message']}");
        }
        return $respuesta['result'] ?? [];
    }

    // Lee un mensaje de Chrome, si llega antes de que pase el tiempo indicado.
    private function leer(float $segundos): void
    {
        $mensaje = $this->leer_marco($segundos);
        if ($mensaje === null) {
            return;
        }
        $datos = json_decode($mensaje, true);
        if (isset($datos['id'])) {
            $this->cola[$datos['id']] = $datos;
        } elseif (($datos['method'] ?? '') === 'Fetch.requestPaused') {
            $this->filtrar($datos['params']);
        } elseif (($datos['method'] ?? '') === 'Network.responseReceivedExtraInfo') {
            // Red a la que Chrome se ha conectado de verdad. Suele llegar antes de la pausa de la respuesta, pero
            // no siempre (y hay respuestas sin identificador de red): por eso esta comprobación es una defensa
            // adicional y la principal es que Chrome solo pueda resolver los hosts fijados.
            if (isset($datos['params']['requestId'], $datos['params']['resourceIPAddressSpace'])) {
                $this->espacios[(string) $datos['params']['requestId']] = (string) $datos['params']['resourceIPAddressSpace'];
            }
        } elseif (($datos['method'] ?? '') === 'Page.loadEventFired') {
            $this->cola['Page.loadEventFired'] = true;
        }
    }

    private function filtrar(array $peticion): void
    {
        $id = ['requestId' => $peticion['requestId']];
        $url = (string) ($peticion['request']['url'] ?? '');
        $respuesta = isset($peticion['responseStatusCode']) || isset($peticion['responseErrorReason']);
        if ($respuesta) {
            // Segunda pasada: la respuesta ya ha llegado a Chrome, pero todavía no a la página
            if (isset($peticion['responseErrorReason'])) {
                // La petición ha fallado en la red: no hay respuesta que proteger, se deja fallar igual
                $this->escribir(json_encode(['id' => ++$this->siguiente_id, 'method' => 'Fetch.failRequest',
                    'params' => $id + ['errorReason' => (string) $peticion['responseErrorReason']]]));
                return;
            }
            $red = (string) ($peticion['networkId'] ?? '');
            $espacio = $this->espacios[$red] ?? null;
            unset($this->espacios[$red]);
            $permitida = preg_match('/^(data|blob):/i', $url) || self::respuesta_permitida($espacio, $this->esta_fijado($url));
            if (!$permitida) {
                $this->descartar($url);
            }
        } else {
            $this->seguridad['peticiones']++;
            $permitida = ++$this->peticiones <= $this->max_peticiones
                && in_array($peticion['request']['method'], ['GET', 'HEAD', 'OPTIONS'], true) && $this->destino_seguro($url);
            if (!$permitida) {
                $this->seguridad['rechazadas']++;
            }
        }
        $this->escribir(json_encode(['id' => ++$this->siguiente_id,
            'method' => $permitida ? 'Fetch.continueRequest' : 'Fetch.failRequest',
            'params' => $permitida ? $id : $id + ['errorReason' => 'BlockedByClient']]));
    }

    private function descartar(string $url): void
    {
        $this->seguridad['respuestas_descartadas']++;
        // Ese destino no se vuelve a pedir en esta sesión
        $this->hosts[$this->clave_destino($url)] = false;
    }

    /** Esquema, host y puerto: un mismo host puede ser válido en el 443 y no en el 8080. */
    private function clave_destino(string $url): string
    {
        $p = parse_url($url) ?: [];
        $esquema = strtolower((string) ($p['scheme'] ?? ''));
        return $esquema . '://' . strtolower((string) ($p['host'] ?? '')) . ':' . (int) ($p['port'] ?? ($esquema === 'https' ? 443 : 80));
    }

    /** ¿El host de la URL está fijado a su IP validada (o es una IP literal, que no pasa por el DNS)? */
    private function esta_fijado(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return isset($this->fijados[$host]) || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function destino_seguro(string $url): bool
    {
        if (preg_match('/^(data|blob):/i', $url)) {
            return true;
        }
        $clave = $this->clave_destino($url);
        if (!isset($this->hosts[$clave])) {
            try {
                // v3.0: mismas reglas que las descargas (solo http/https, puertos 80/443, sin IP privadas)
                $destino = $this->guard->validate($url);
                $this->hosts[$clave] = true;
                if (!$destino['is_ip_literal']) {
                    $this->validados[$destino['host']] = $destino['ip'];
                }
            } catch (Throwable $e) {
                $this->hosts[$clave] = false;
            }
        }
        return $this->hosts[$clave];
    }

    private function conectar(int $puerto, string $ruta): void
    {
        $this->socket = @stream_socket_client("tcp://127.0.0.1:$puerto", $codigo, $error, 5);
        if (!$this->socket) {
            throw new RuntimeException("No se puede conectar con Chrome: $error");
        }
        $clave = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET $ruta HTTP/1.1\r\nHost: 127.0.0.1:$puerto\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $clave\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($this->socket, 5);
        while (!str_contains($this->buffer, "\r\n\r\n")) {
            $trozo = fread($this->socket, 4096);
            if ($trozo === '' || $trozo === false) {
                throw new RuntimeException('Chrome ha rechazado la conexión.');
            }
            $this->buffer .= $trozo;
        }
        [$cabecera, $this->buffer] = explode("\r\n\r\n", $this->buffer, 2);
        if (!str_contains($cabecera, ' 101 ')) {
            throw new RuntimeException('Chrome ha rechazado la conexión.');
        }
        stream_set_blocking($this->socket, false);
    }

    // Envía un marco de texto de WebSocket. El cliente siempre debe enmascararlo.
    private function escribir(string $texto): void
    {
        $largo = strlen($texto);
        $marco = "\x81" . match (true) {
            $largo < 126 => chr($largo | 0x80),
            $largo < 65536 => chr(126 | 0x80) . pack('n', $largo),
            default => chr(127 | 0x80) . pack('J', $largo),
        };
        $mascara = random_bytes(4);
        $marco .= $mascara . ($texto ^ str_repeat($mascara, intdiv($largo, 4) + 1));
        stream_set_blocking($this->socket, true);
        fwrite($this->socket, $marco);
        stream_set_blocking($this->socket, false);
    }

    // Devuelve un mensaje completo o null. Chrome envía los marcos sin máscara.
    private function leer_marco(float $segundos): ?string
    {
        $limite = microtime(true) + $segundos;
        $mensaje = '';
        while (true) {
            $marco = $this->extraer_marco();
            if ($marco !== null) {
                [$fin, $datos] = $marco;
                $mensaje .= $datos;
                if ($fin) {
                    return $mensaje;
                }
                continue;
            }
            $resto = $limite - microtime(true);
            if ($resto <= 0 && $mensaje === '') {
                return null;
            }
            if ($resto < -30) {
                // Mensaje a medias que no termina de llegar: no se espera indefinidamente
                throw new RuntimeException('Chrome ha dejado un mensaje a medias.');
            }
            $lectura = [$this->socket];
            $nada = null;
            $espera = (int) (max($resto, 0.05) * 1000000);
            if (stream_select($lectura, $nada, $nada, 0, $espera) > 0) {
                $trozo = fread($this->socket, 1048576);
                if ($trozo === '' && feof($this->socket)) {
                    throw new RuntimeException('Chrome se ha cerrado.');
                }
                $this->buffer .= (string) $trozo;
            }
        }
    }

    private function extraer_marco(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }
        $primero = ord($this->buffer[0]);
        $largo = ord($this->buffer[1]) & 0x7F;
        $inicio = 2;
        if ($largo === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }
            $largo = unpack('n', substr($this->buffer, 2, 2))[1];
            $inicio = 4;
        } elseif ($largo === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }
            $largo = unpack('J', substr($this->buffer, 2, 8))[1];
            $inicio = 10;
        }
        if (strlen($this->buffer) < $inicio + $largo) {
            return null;
        }
        $datos = substr($this->buffer, $inicio, $largo);
        $this->buffer = substr($this->buffer, $inicio + $largo);
        return [(bool) ($primero & 0x80), $datos];
    }
}

function ruta_chrome(array $config): ?string
{
    $posibles = [$config['chrome_ruta'],
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
    foreach ($posibles as $ruta) {
        if ($ruta !== '' && is_file($ruta)) {
            return $ruta;
        }
    }
    return null;
}

function borrar_carpeta(string $carpeta): void
{
    foreach (@scandir($carpeta) ?: [] as $nombre) {
        if ($nombre !== '.' && $nombre !== '..') {
            $ruta = $carpeta . DIRECTORY_SEPARATOR . $nombre;
            is_dir($ruta) && !is_link($ruta) ? borrar_carpeta($ruta) : @unlink($ruta);
        }
    }
    @rmdir($carpeta);
}
