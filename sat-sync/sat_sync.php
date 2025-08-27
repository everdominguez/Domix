<?php
declare(strict_types=1);

use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\StatesVoucherOption;
use PhpCfdi\CfdiSatScraper\ResourceType;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecSessionManager;
use PhpCfdi\ImageCaptchaResolver\BoxFacturaAI\BoxFacturaAIResolver;

use GuzzleHttp\Client as GuzzleClient;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;

// ==== AUTLOAD: prioriza vendor/ del raíz de tu proyecto ====
$autoloads = [
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];
$loaded = false;
foreach ($autoloads as $file) {
    if (file_exists($file)) { require $file; $loaded = true; break; }
}
if (!$loaded) {
    fwrite(STDERR, "No se encontró vendor/autoload.php. Instala dependencias en el raíz o ajusta rutas.\n");
    exit(1);
}

date_default_timezone_set('America/Chihuahua');

$companies = require __DIR__ . '/sat_sync.env.php';

function ensureDir(string $path): void { if (!is_dir($path)) { mkdir($path, 0775, true); } }
function logmsg(string $msg): void {
  $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
  ensureDir(__DIR__ . '/logs');
  file_put_contents(__DIR__.'/logs/sat_sync.log', $line, FILE_APPEND);
  echo $line;
}
function retry(callable $fn, int $times=3, int $sleep=2) {
  $e = null;
  for ($i=0; $i<$times; $i++) {
    try { return $fn(); } catch (Throwable $ex) { $e = $ex; sleep($sleep); }
  }
  throw $e;
}

// ====== CLI FLAGS ======
$argvMap = [];
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) { $argvMap[$m[1]] = $m[2]; }
}
// rango
$from = isset($argvMap['from']) ? new DateTimeImmutable($argvMap['from']) : new DateTimeImmutable('-1 day 00:00:00');
$to   = isset($argvMap['to'])   ? new DateTimeImmutable($argvMap['to'])   : new DateTimeImmutable('now');
// flags formato
$bool = static fn($v) => in_array(strtolower((string)$v), ['1','true','yes','y','on'], true);
$downloadPdf  = $bool($argvMap['pdf']  ?? 'false');  // solo PDF
$downloadBoth = $bool($argvMap['both'] ?? 'false');  // XML + PDF

// resolver captcha
$bfModelDir = getenv('BOXFACTURA_MODEL_DIR') ?: (__DIR__ . '/storage/boxfactura-model');
$configsFile = rtrim($bfModelDir, '/').'/configs.yaml';
if (!file_exists($configsFile)) {
  logmsg("ERROR: No existe $configsFile. Ejecuta el script de descarga del modelo.");
  exit(1);
}
$captchaResolver = BoxFacturaAIResolver::createFromConfigs($configsFile);

// HTTP Gateway con TLS y UA “real”
$guzzle = new GuzzleClient([
  'timeout' => 30,
  'connect_timeout' => 15,
  'headers' => [
    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
  ],
  'verify' => true,
  'curl' => [
    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
    CURLOPT_SSLVERSION      => CURL_SSLVERSION_TLSv1_2,
  ],
]);
$gateway = new SatHttpGateway($guzzle);

foreach ($companies as $c) {
  $baseDir = rtrim($c['download_dir'], '/');
  // Carpeta por fecha (única). Aquí van XML y PDF juntos
  $runDirBase = $baseDir . '/' . (new DateTime())->format('Y-m-d');
  ensureDir($runDirBase);

  try {
    // Login CIEC + captcha + gateway
    $sat = new SatScraper(
      CiecSessionManager::create($c['rfc'], $c['ciec'], $captchaResolver),
      $gateway
    );

    // valida sesión
    $sat->confirmSessionIsAlive();

    foreach ($c['modes'] as $mode) {
      $scope = ($mode === 'emitidos') ? DownloadType::emitidos() : DownloadType::recibidos();

      // === UNA SOLA CONSULTA DEL RANGO COMPLETO ===
      $query = new QueryByFilters($from, $to);
      $query->setDownloadType($scope)
            ->setStateVoucher(StatesVoucherOption::vigentes()); // quita para incluir cancelados

      $what = $downloadBoth ? 'XML+PDF' : ($downloadPdf ? 'PDF' : 'XML');
      logmsg("{$c['company_name']} {$mode} ({$what}): consultando {$from->format('c')} → {$to->format('c')}");

      $list = retry(fn() => $sat->listByPeriod($query), 3, 2);
      $count = count($list);
      logmsg("{$c['company_name']} {$mode}: encontrados $count CFDI");

      if ($count > 0) {
        // === decidir qué descargar ===
        $doXml = $downloadBoth || (!$downloadPdf); // por defecto XML a menos que pidas solo PDF
        $doPdf = $downloadBoth || $downloadPdf;

        // NOTA: Guardamos TODO en $runDirBase
        if ($doXml) {
          $xmlDownloader = $sat->resourceDownloader(ResourceType::xml(), $list);
          $xmlUuids = $xmlDownloader->saveTo($runDirBase);
          $manifestXml = $runDirBase . '/manifest_xml_' . date('Ymd_His') . '.txt';
          file_put_contents($manifestXml, implode(PHP_EOL, $xmlUuids) . PHP_EOL);
          logmsg("Guardados XML en: $runDirBase (UUIDs en " . basename($manifestXml) . ")");
        }

        if ($doPdf) {
          $pdfDownloader = $sat->resourceDownloader(ResourceType::pdf(), $list);
          $pdfUuids = $pdfDownloader->saveTo($runDirBase);
          $manifestPdf = $runDirBase . '/manifest_pdf_' . date('Ymd_His') . '.txt';
          file_put_contents($manifestPdf, implode(PHP_EOL, $pdfUuids) . PHP_EOL);
          logmsg("Guardados PDF en: $runDirBase (UUIDs en " . basename($manifestPdf) . ")");
        }
      }
    }

    logmsg("{$c['company_name']}: ciclo completado (descarga en carpeta única por fecha).");
  } catch (Throwable $e) {
    logmsg("{$c['company_name']}: ERROR → " . $e->getMessage());
  }
}
