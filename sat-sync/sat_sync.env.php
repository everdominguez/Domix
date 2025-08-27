<?php
// sat_sync.env.php
return [
  [
    'company_id'   => 2,                      // tu ID interno (DEMS, por ejemplo)
    'company_name' => 'DEMS',
    'rfc'          => 'PID241016TY8',        // RFC
    'ciec'         => getenv('DEMS_CIEC') ?: 'Mexico86',
    'modes'        => ['recibidos'],          // ['recibidos','emitidos'] si deseas ambos
    'download_dir' => __DIR__ . '/temp_xmls/DEMS', // destino de XML
  ],
  // ... puedes añadir más empresas aquí
];
