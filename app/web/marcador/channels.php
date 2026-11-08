<?php
declare(strict_types=1);
// Marcador: channel control when dialing. The channels ASSIGNED to the STARTED lists (running, also those waiting for retries) may not add up to more than
// max_total_channels. Active lists that are not started and the calls open at that moment do not count. The rule lives in the service, never in the screen.

/** Thrown when starting lists, raising the channels of a started list or lowering the server maximum would leave the started lists above the maximum. Nothing was saved. $figures: discando, maximo, pedidos, libres, faltan. */
class BotChannelsException extends RuntimeException {
    /** @var array{discando:int,maximo:int,pedidos:int,libres:int,faltan:int} */
    public array $figures;
    /** $kind: 'start' (one list), 'edit' (channels of a started list), 'batch' (several lists, all or nothing), 'max' (lowering the server maximum: $maximo is the NEW maximum, $pedidos 0). $discando = channels of the other started lists. */
    public function __construct(int $discando, int $maximo, int $pedidos, string $kind) {
        $libres = max(0, $maximo - $discando);
        $this->figures = ['discando'=>$discando, 'maximo'=>$maximo, 'pedidos'=>$pedidos, 'libres'=>$libres, 'faltan'=>max(0, $discando + $pedidos - $maximo)];
        $head = "Sobredimensionamiento de canales: discando $discando de $maximo";
        parent::__construct(match ($kind) {
            'max' => "Sobredimensionamiento de canales: discando $discando de nuevo $maximo; el máximo no puede quedar por debajo de lo que se disca. Detén listas o baja sus canales primero.",
            'edit' => "$head (sin contar esta lista); pides $pedidos y solo quedan $libres libres. Baja el valor o detén otra lista.",
            'batch' => "$head; las listas piden $pedidos y solo quedan $libres libres; faltan {$this->figures['faltan']} canales. No se inició ninguna lista.",
            default => "$head; esta lista pide $pedidos y solo quedan $libres libres. Baja sus canales o detén otra lista.",
        });
    }
}
