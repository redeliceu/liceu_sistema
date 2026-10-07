<?php
declare(strict_types=1);
function whatsappConfig(): array {
    $file=dirname(__DIR__).'/private-config.php';
    $root=is_file($file)?(require $file):[];
    $w=is_array($root['whatsapp']??null)?$root['whatsapp']:[];
    return [
        'enabled'=>(bool)($w['enabled']??false),
        'api_version'=>(string)($w['api_version']??'v23.0'),
        'phone_number_id'=>trim((string)($w['phone_number_id']??'')),
        'business_account_id'=>trim((string)($w['business_account_id']??'')),
        'access_token'=>trim((string)($w['access_token']??'')),
        'verify_token'=>trim((string)($w['verify_token']??'')),
        'template_falta'=>trim((string)($w['template_falta']??'')),
        'template_language'=>(string)($w['template_language']??'pt_BR'),
    ];
}
function whatsappConfigPublica(): array {
    $c=whatsappConfig();
    $campos=['phone_number_id','access_token','verify_token','template_falta'];
    $faltando=[]; foreach($campos as $k) if($c[$k]==='')$faltando[]=$k;
    return ['enabled'=>$c['enabled'],'apiVersion'=>$c['api_version'],'phoneNumberIdConfigurado'=>$c['phone_number_id']!=='','tokenConfigurado'=>$c['access_token']!=='','verifyTokenConfigurado'=>$c['verify_token']!=='','templateFalta'=>$c['template_falta'],'templateLanguage'=>$c['template_language'],'pronto'=>$c['enabled']&&!$faltando,'faltando'=>$faltando];
}
