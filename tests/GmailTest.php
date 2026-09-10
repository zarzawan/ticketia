<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/gmail.php';
final class GmailTest extends TestCase {
    public function testDiagnosticoNoExponeValoresPrivados(): void {
        $valores=['GMAIL_CLIENT_ID'=>'id-privado','GMAIL_CLIENT_SECRET'=>'secreto-privado','GMAIL_REFRESH_TOKEN'=>'token-privado','GMAIL_BUZON'=>'soporte@example.test','GMAIL_LABEL_ID'=>'Label_123'];
        $estado=gmail_diagnostico(static fn($clave)=>$valores[$clave] ?? '',true);
        $this->assertTrue($estado['completo']);
        foreach($valores as $valor) $this->assertStringNotContainsString($valor,json_encode($estado));
        $this->assertFalse(gmail_diagnostico(static fn($clave)=>$valores[$clave] ?? '',false)['completo']);
    }
    public function testDiagnosticoDistingueAusenciasYFormatosInvalidos(): void {
        $valores=['GMAIL_BUZON'=>'correo-invalido','GMAIL_LABEL_ID'=>'Etiqueta con espacios','GMAIL_CLIENT_SECRET'=>'   '];
        $estado=gmail_diagnostico(static fn($clave)=>$valores[$clave] ?? '',true);
        $this->assertFalse($estado['completo']);
        $this->assertSame(['GMAIL_CLIENT_ID','GMAIL_CLIENT_SECRET','GMAIL_REFRESH_TOKEN'],$estado['faltan']);
        $this->assertSame(['GMAIL_BUZON','GMAIL_LABEL_ID'],$estado['invalidos']);
    }
    public function testExtraeTextoSinInterpretarHtmlNiRemitentesAmbiguos(): void {
        $mensaje=['payload'=>['mimeType'=>'text/html','headers'=>[['name'=>'From','value'=>'Cliente <cliente@example.test>'],['name'=>'Subject','value'=>'Consulta']],
            'body'=>['data'=>rtrim(strtr(base64_encode('<script>NO_EJECUTAR</script><p>Hola</p>'),'+/','-_'),'=')]]];
        $m=gmail_extraer($mensaje);
        $this->assertSame('cliente@example.test',$m['remitente']);
        $this->assertSame('Hola',$m['cuerpo']);
        $this->assertStringContainsString('HTML convertido',$m['aviso']);
        $mensaje['payload']['headers'][]=['name'=>'From','value'=>'otra@example.test'];
        $this->assertSame('',gmail_extraer($mensaje)['remitente']);
    }
    public function testLimitaContenidoYAdvierteAdjuntos(): void {
        $m=gmail_extraer(['payload'=>['mimeType'=>'multipart/mixed','parts'=>[
            ['mimeType'=>'text/plain','body'=>['data'=>base64_encode(str_repeat('a',30001))]],
            ['filename'=>'secreto.pdf','body'=>['attachmentId'=>'no_descargar']]]]]);
        $this->assertSame(30000,mb_strlen($m['cuerpo']));
        $this->assertStringContainsString('Adjuntos no importados',$m['aviso']);
        $this->assertStringContainsString('recortado',$m['aviso']);
    }
}
