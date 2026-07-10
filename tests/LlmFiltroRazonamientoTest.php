<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El filtro de razonamiento elimina el "pensamiento" de los modelos reasoner
 * y deja solo la respuesta final. Cubre <think>...</think> y los canales
 * estilo harmony/gpt-oss, incluidas las variantes degradadas de LM Studio.
 */
final class LlmFiltroRazonamientoTest extends TestCase
{
    // ------------------------- llm_strip_razonamiento -------------------------

    public static function casosStrip(): array
    {
        return [
            'texto normal intacto' => [
                'Hola, la incidencia esta resuelta.',
                'Hola, la incidencia esta resuelta.',
            ],
            'par think' => [
                "<think>El usuario pregunta X, deberia responder Y.</think>La respuesta es Y.",
                'La respuesta es Y.',
            ],
            'par thinking multilinea' => [
                "<thinking>linea 1\nlinea 2</thinking>\nRespuesta final.",
                'Respuesta final.',
            ],
            'cierre sin apertura (respuesta parcial)' => [
                "razonamiento cortado...</think>Solo esto debe quedar.",
                'Solo esto debe quedar.',
            ],
            'harmony analysis + final' => [
                "<|channel|>analysis<|message|>Pienso en el problema.<|end|><|channel|>final<|message|>Respuesta al usuario.",
                'Respuesta al usuario.',
            ],
            'harmony degradado de LM Studio' => [
                "<|channel>thought Estoy pensando el resumen.<channel|>El resumen definitivo.",
                'El resumen definitivo.',
            ],
        ];
    }

    #[DataProvider('casosStrip')]
    public function testStripRazonamiento(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, llm_strip_razonamiento($entrada));
    }

    // ---------------------- LLMFiltroRazonamiento (streaming) ----------------------

    /** Une lo que emite el filtro para una lista de chunks. */
    private function filtrar(array $chunks): string
    {
        $filtro = new LLMFiltroRazonamiento();
        $salida = '';
        foreach ($chunks as $chunk) {
            $salida .= $filtro->procesar($chunk);
        }
        return $salida . $filtro->finalizar();
    }

    public function testStreamSinRazonamientoPasaIntacto(): void
    {
        $this->assertSame(
            'Hola, respuesta directa.',
            $this->filtrar(['Hola, ', 'respuesta ', 'directa.'])
        );
    }

    public function testStreamConThinkEnUnSoloChunk(): void
    {
        $this->assertSame(
            'Resultado.',
            $this->filtrar(['<think>pensando</think>Resultado.'])
        );
    }

    public function testStreamConThinkPartidoEntreChunks(): void
    {
        $this->assertSame(
            'Resultado final.',
            $this->filtrar(['<thi', 'nk>razonamiento ', 'largo</th', 'ink>Resultado final.'])
        );
    }

    public function testStreamHarmonyPartidoEntreChunks(): void
    {
        $chunks = [
            '<|channel|>analysis<|message|>Voy a ',
            'analizar el ticket con calma.',
            '<|end|><|channel|>final',
            '<|message|>La incidencia es de red.',
        ];
        $this->assertSame('La incidencia es de red.', $this->filtrar($chunks));
    }

    public function testStreamHarmonyDegradadoDeLmStudio(): void
    {
        $chunks = [
            '<|channel>thought Primero miro los sintomas ',
            'y despues decido.',
            '<channel|>Diagnostico: disco lleno.',
        ];
        $this->assertSame('Diagnostico: disco lleno.', $this->filtrar($chunks));
    }

    public function testStreamSoloRazonamientoNoEmiteNada(): void
    {
        $this->assertSame('', trim($this->filtrar(['<think>solo pienso, nunca respondo'])));
    }
}
