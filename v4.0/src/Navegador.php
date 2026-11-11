<?php
// v3.0: copiado de la v1.0 (lib/navegador.php). Controla Chrome sin Composer por el protocolo DevTools.
// Las funciones ruta_chrome() y borrar_carpeta() se cargan junto con la clase.
// Controla un Chrome sin ventana desde PHP, hablando con él por el protocolo de DevTools.

class Navegador
{
    private $proceso;
    private $socket;
    private string $perfil;
    private string $buffer = '';
    private int $siguiente_id = 0;
    private array $cola = [];
    private array $hosts = [];

    public function __construct(private array $config)
    {
        $ruta = ruta_chrome($config);
        if ($ruta === null) {
            throw new RuntimeException('No se ha encontrado Chrome.');
        }
        $this->perfil = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'web_seo_chrome_' . bin2hex(random_bytes(6));
        mkdir($this->perfil);

        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open([$ruta, '--headless=new', '--remote-debugging-port=0', "--user-data-dir={$this->perfil}",
            '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-sync',
            '--disable-background-networking', '--disable-component-update', '--mute-audio', '--window-size=1280,800',
            ...($config['chrome_sin_sandbox'] ? ['--no-sandbox'] : []), 'about:blank'],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $tuberias, null, null, ['bypass_shell' => true]);
        if (!is_resource($this->proceso)) {
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
        // Toda petición pasa por aquí: se bloquea lo interno y cualquier envío de datos.
        $this->enviar('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]);
        $this->enviar('Network.enable');
        $this->enviar('Network.setBlockedURLs', ['urls' => ['ws://*', 'wss://*']]);
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

    public function cerrar(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        if (is_resource($this->proceso)) {
            $pid = proc_get_status($this->proceso)['pid'];
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID $pid 2>NUL");
            } else {
                proc_terminate($this->proceso, 9);
            }
            proc_close($this->proceso);
        }
        // Chrome tarda un momento en soltar sus archivos.
        for ($i = 0; $i < 10 && is_dir($this->perfil); $i++) {
            borrar_carpeta($this->perfil);
            usleep(200000);
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
        } elseif (($datos['method'] ?? '') === 'Page.loadEventFired') {
            $this->cola['Page.loadEventFired'] = true;
        }
    }

    private function filtrar(array $peticion): void
    {
        $id = ['requestId' => $peticion['requestId']];
        $permitida = in_array($peticion['request']['method'], ['GET', 'HEAD', 'OPTIONS'], true) && $this->destino_seguro($peticion['request']['url']);
        $this->escribir(json_encode(['id' => ++$this->siguiente_id,
            'method' => $permitida ? 'Fetch.continueRequest' : 'Fetch.failRequest',
            'params' => $permitida ? $id : $id + ['errorReason' => 'BlockedByClient']]));
    }

    private function destino_seguro(string $url): bool
    {
        if (preg_match('/^(data|blob):/i', $url)) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!isset($this->hosts[$host])) {
            try {
                // v3.0: mismas reglas que las descargas (solo http/https, puertos 80/443, sin IP privadas)
                (new UrlGuard())->validate($url);
                $this->hosts[$host] = true;
            } catch (Throwable $e) {
                $this->hosts[$host] = false;
            }
        }
        return $this->hosts[$host];
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
