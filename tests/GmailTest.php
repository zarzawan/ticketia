<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/gmail.php';
final class GmailTest extends TestCase {
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
