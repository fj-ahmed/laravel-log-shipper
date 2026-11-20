<?php
// Run: php tests/masker-check.php .   (from the package root) — keep in parity with the collector's masking tests
function env($k, $d = null) { return $d; }
function storage_path($p = '') { return $p; }
require $argv[1] . '/src/Masker.php';
$c = require $argv[1] . '/config/log-shipper.php';
$m = new FjAhmed\LogShipper\Masker($c['mask_keys'], $c['mask_exact_keys']);
$M = FjAhmed\LogShipper\Masker::MASK;
$jwt = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwcyJ9.753CDDZ0K0EurXY5j5aSFoctuZN3';
$checks = [
  [$m->mask(['email'=>'a@b.c','password'=>'x','headers'=>['authorization'=>['Bearer abc']],'otp'=>'1','shipping'=>'ok','_token'=>$jwt]),
   ['email'=>'a@b.c','password'=>$M,'headers'=>['authorization'=>$M],'otp'=>$M,'shipping'=>'ok','_token'=>$M]],
  [$m->maskString("auth failed for $jwt done"), "auth failed for $M done"],
  [$m->maskString('Authorization: Bearer abcdefghijkl'), "Authorization: Bearer $M"],
  [$m->maskString('https://x.local/api?a=1&access_token=abc123&b=2'), "https://x.local/api?a=1&access_token=$M&b=2"],
  [$m->maskString("artisan tinker --execute=Log::info(x, [password=>secret123,to=>1]);"), "artisan tinker --execute=Log::info(x, [password=>$M"],
  [$m->maskString("login ['password' => 'hunter2', 'email' => 'a@b.c']"), "login ['password' => '$M', 'email' => 'a@b.c']"],
  [$m->maskString('/api/v1/notification/send/text?to=1&token=abc'), "/api/v1/notification/send/text?to=1&token=$M"],
  [$m->maskString('password=x&name=bob'), "password=$M&name=bob"],
  [$m->maskString('{"status":true,"data":{"token":"abc","name":"Al"}}'), "{\"status\":true,\"data\":{\"token\":\"$M\",\"name\":\"Al\"}}"],
  [$m->maskString('{"a":1,"access_token":"x\"y","name":"Al","pass'), "{\"a\":1,\"access_token\":\"$M\",\"name\":\"Al\",\"pass"],
  [$m->maskString("MHH Request: => Array\n(\n    [email] => a@b.c\n    [password] => hunter2\n    [api_token] => Array\n"),
   "MHH Request: => Array\n(\n    [email] => a@b.c\n    [password] => $M\n    [api_token] => Array\n"],
];
$fail = 0;
foreach ($checks as $i => [$got, $want]) if ($got !== $want) { $fail++; echo "FAIL $i\n"; var_export($got); echo "\n"; }
echo $fail ? "$fail failed\n" : "php masker: all " . count($checks) . " ok\n";
