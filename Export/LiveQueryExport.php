<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Export;

use Storm\LiveQuery\Output\LiveQueryDocument;

/**
 * The outbound port of the inspection: storm emits the result document through it, once per run,
 * and knows nothing about where it goes. The adapter is the app's policy: the destination such as a
 * bucket, db, file, or api, the handling, and the process-cost call. A heavy document goes async on
 * the app side; storm stays a one-shot CLI. Storm ships no adapter; bind an implementation on this
 * interface and the command autowires it.
 *
 * Invocation is operator-opt-in through `--export`, never implicit: a bound adapter stays dormant
 * until asked, because an inspection must not carry side effects the operator did not request. The
 * document arrives complete and bounded, with `--limit` applied and meta carrying source, count,
 * and truncated, one destination per run. Fan-out, if wanted, lives inside the adapter, which can
 * route by meta-source such as per recipe. An adapter failure throws, and the run reports FAILURE,
 * since emission was part of the asked gesture.
 *
 * If routing-by-recipe adapters become a repeated pattern, the vehicle is an opt-in companion
 * interface on the recipe side such as `ExportAwareRecipe`, never this contract nor the recipe base
 * contract: a recipe shapes a fetch, delivery is another trade.
 */
interface LiveQueryExport
{
    /**
     * Emit the complete, bounded result document to the adapter's configured destination.
     */
    public function export(LiveQueryDocument $document): void;
}
