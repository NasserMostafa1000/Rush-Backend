<?php

return [
    /*
    | H3 resolution for driver/order proximity.
    | 7 ? 1.2km edge, 8 ? 460m, 9 ? 174m
    */
    'resolution' => (int) env('H3_RESOLUTION', 8),

    /*
    | Ring size (k) around the order pickup cell.
    | k=2 at res 8 ? ~1–2 km neighborhood.
    */
    'ring_k' => (int) env('H3_RING_K', 2),
];
