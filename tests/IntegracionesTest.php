<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/integraciones.php';
final class IntegracionesTest extends TestCase {
    private $appKey;
    private $soloLocal;
    protected function setUp(): void {
        $this->appKey=getenv('APP_KEY');$this->soloLocal=getenv('LLM_SOLO_LOCAL');
        putenv('APP_KEY=clave-sintetica-de-pruebas-no-produccion');putenv('LLM_SOLO_LOCAL=0');
    }
    protected function tearDown(): void {
        putenv($this->appKey===false ? 'APP_KEY' : 'APP_KEY=' . $this->appKey);
        putenv($this->soloLocal===false ? 'LLM_SOLO_LOCAL' : 'LLM_SOLO_LOCAL=' . $this->soloLocal);
        unset($GLOBALS['integraciones_valores']);
    }
    private function gmail(): array {
        return ['GMAIL_BUZON'=>'soporte@example.test','GMAIL_LABEL_ID'=>'Label_123','GMAIL_CLIENT_ID'=>'cliente-sintetico','GMAIL_CLIENT_SECRET'=>'secreto-sintetico','GMAIL_REFRESH_TOKEN'=>'refresh-sintetico'];
    }
    public function testCifradoProtegeValoresYVinculaGrupo(): void {
        $datos=$this->gmail();$cifrado=cuenta_secreto_cifrar(json_encode(['grupo'=>'gmail','valores'=>$datos]));
        $this->assertStringNotContainsString('secreto-sintetico',$cifrado);
        $this->assertSame($datos,integraciones_descifrar('gmail',$cifrado));
        $this->expectException(RuntimeException::class);integraciones_descifrar('openai',$cifrado);
    }
    public function testClavePerdidaNoDescifra(): void {
        $cifrado=cuenta_secreto_cifrar(json_encode(['grupo'=>'gmail','valores'=>$this->gmail()]));
        putenv('APP_KEY=otra-clave-sintetica-de-pruebas-diferente');
        $this->expectException(RuntimeException::class);integraciones_descifrar('gmail',$cifrado);
    }
    public function testVacioConservaSecretosYNormalizaBuzon(): void {
        $entrada=$this->gmail();$entrada['GMAIL_CLIENT_SECRET']='';$entrada['GMAIL_BUZON']='SOPORTE@example.test';
        $valores=integraciones_validar('gmail',$entrada,fn($clave)=>$this->gmail()[$clave]);
        $this->assertSame($this->gmail(),$valores);
    }
    public function testRechazaInyeccionDeCabeceras(): void {
        $entrada=$this->gmail();$entrada['GMAIL_REFRESH_TOKEN']="token\r\nCabecera: mala";
        $this->expectException(RuntimeException::class);integraciones_validar('gmail',$entrada,fn()=> '');
    }
    public function testGmailSoloConsultaPerfilYEtiqueta(): void {
        $rutas=[];
        $resultado=integraciones_probar('gmail',$this->gmail(),function($url,$form,$token)use(&$rutas){
            $rutas[]=$url;
            if($form!==null){$this->assertSame('refresh_token',$form['grant_type']);return ['access_token'=>'acceso-sintetico'];}
            $this->assertSame('acceso-sintetico',$token);
            return str_ends_with($url,'profile') ? ['emailAddress'=>'soporte@example.test'] : ['id'=>'Label_123'];
        });
        $this->assertCount(3,$rutas);$this->assertStringContainsString('Conexion Gmail correcta',$resultado);
        $this->assertStringNotContainsString('messages',implode(' ',$rutas));
    }
    public function testGmailRechazaCuentaDistinta(): void {
        $this->expectExceptionMessage('no coincide');
        integraciones_probar('gmail',$this->gmail(),fn($url,$form)=>$form ? ['access_token'=>'acceso'] : ['emailAddress'=>'ajeno@example.test']);
    }
    public function testPruebaIaNoGeneraTextoNiCambiaStreaming(): void {
        foreach(['openai'=>'OPENAI','xai'=>'XAI'] as $grupo=>$prefijo) {
            $rutas=[];$datos=[$prefijo.'_API_KEY'=>'token-sintetico',$prefijo.'_MODEL'=>'modelo-1',$prefijo.'_MODEL_STREAM'=>'modelo-2'];
            $resultado=integraciones_probar($grupo,$datos,function($url,$form,$token)use(&$rutas){$rutas[]=$url;$this->assertNull($form);return ['id'=>'modelo'];});
            $this->assertCount(2,$rutas);$this->assertStringContainsString('/v1/models/modelo-2',$rutas[1]);
            $this->assertStringContainsString('no garantiza saldo',$resultado);
        }
    }
    public function testSoloLocalImpideSolicitudExterna(): void {
        putenv('LLM_SOLO_LOCAL=1');$this->expectExceptionMessage('LLM_SOLO_LOCAL');
        integraciones_probar('openai',[],function(){self::fail('No debe contactar al proveedor');});
    }
    public function testDestinoHttpNoAdmiteServidorArbitrario(): void {
        $this->expectExceptionMessage('Destino de conexion no permitido');
        integraciones_http('https://ajeno.example.test/modelos',null,'secreto');
    }
    public function testPanelTienePrioridadSoloEnCamposPermitidos(): void {
        $GLOBALS['integraciones_valores']=['OPENAI_MODEL'=>'modelo-panel'];
        $this->assertSame('modelo-panel',entorno_valor('OPENAI_MODEL','modelo-entorno'));
        $this->assertSame('0',entorno_valor('LLM_SOLO_LOCAL'));
    }
}
