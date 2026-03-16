<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Token;
use App\Repositories\PrintSettingRepository;
use App\Services\TokenPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per docs/plans/QR-TOKEN-PRINT-SYSTEM.md: Token print template preview.
 * Options from query params override saved PrintSetting.
 */
class TokenPrintController extends Controller
{
    public function __construct(
        private TokenPrintService $tokenPrintService,
        private PrintSettingRepository $printSettingRepository
    ) {}

    /**
     * Render print template. Query: ?ids=1,2,3 or all tokens if no ids.
     * Options (from query or saved settings): cards_per_page, paper, orientation, show_hint, show_cut_lines, logo_url, footer_text.
     */
    public function __invoke(Request $request): Response
    {
        $tokens = $this->resolveTokens($request);
        $saved = $this->printSettingRepository->getInstance();

        $cardsPerPage = $request->has('cards_per_page')
            ? max(4, min(8, (int) $request->query('cards_per_page', 6)))
            : $saved->cards_per_page;
        $paperSize = $request->has('paper')
            ? (in_array($request->query('paper'), ['a4', 'letter'], true) ? $request->query('paper') : 'a4')
            : $saved->paper;
        $orientation = $request->has('orientation')
            ? (in_array($request->query('orientation'), ['portrait', 'landscape'], true) ? $request->query('orientation') : 'portrait')
            : $saved->orientation;
        $showHint = $request->has('hint') ? filter_var($request->query('hint'), FILTER_VALIDATE_BOOLEAN) : $saved->show_hint;
        $showCutLines = $request->has('cutlines') ? filter_var($request->query('cutlines'), FILTER_VALIDATE_BOOLEAN) : $saved->show_cut_lines;
        $logoUrl = $request->has('logo_url') ? $request->query('logo_url') : $saved->logo_url;
        $footerText = $request->has('footer_text') ? $request->query('footer_text') : $saved->footer_text;
        $bgImageUrl = $request->has('bg_image_url') ? $request->query('bg_image_url') : $saved->bg_image_url;

        $result = $this->tokenPrintService->prepareTokensForPrint($tokens);

        $pages = collect($result['cards'])
            ->chunk($cardsPerPage)
            ->map(fn ($chunk) => $chunk->values()->all())
            ->values()
            ->all();

        $grid = match ($cardsPerPage) {
            4 => ['rows' => 2, 'cols' => 2],
            5 => ['rows' => 2, 'cols' => 3],
            6 => ['rows' => 2, 'cols' => 3],
            7 => ['rows' => 2, 'cols' => 4],
            8 => ['rows' => 2, 'cols' => 4],
            default => ['rows' => 2, 'cols' => 3],
        };

        return Inertia::render('Admin/Tokens/Print', [
            'cards' => $result['cards'],
            'pages' => $pages,
            'cardsPerRow' => $grid['cols'],
            'cardsPerColumn' => $grid['rows'],
            'paperSize' => $paperSize,
            'orientation' => $orientation,
            'showHint' => $showHint,
            'showCutLines' => $showCutLines,
            'logoUrl' => $logoUrl,
            'footerText' => $footerText,
            'bgImageUrl' => $bgImageUrl,
            'skipped' => $result['skipped'],
        ]);
    }

    private function resolveTokens(Request $request): Collection
    {
        $ids = $request->query('ids');
        if (is_string($ids) && $ids !== '') {
            $idList = array_map('intval', array_filter(explode(',', $ids)));
            if ($idList !== []) {
                return Token::query()->whereIn('id', $idList)->orderBy('physical_id')->get();
            }
        }

        return Token::query()->orderBy('physical_id')->limit(50)->get();
    }
}
