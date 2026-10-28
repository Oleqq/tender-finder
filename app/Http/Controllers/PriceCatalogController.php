<?php

namespace App\Http\Controllers;

use App\Models\PriceCatalog;
use App\Models\PriceCatalogComparison;
use App\Models\Tender;
use App\Models\User;
use App\Services\PriceCatalog\CatalogMatcher;
use App\Services\PriceCatalog\CatalogService;
use App\Services\PriceCatalog\SpreadsheetImporter;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PriceCatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalogs) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request, false);
        $enabled = $this->catalogs->enabled($user);

        return Inertia::render('PriceCatalog', ['enabled' => $enabled,
            'catalog' => $enabled ? $this->catalogs->present($user) : ['version' => 0, 'filename' => null, 'items' => []]]);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $this->user($request);
        $path = tempnam(sys_get_temp_dir(), 'catalog-');
        abort_if($path === false, 503);
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['Название', 'Характеристики', 'Цена', 'Количество', 'Единица', 'Регион']));
            $writer->addRow(Row::fromValues(['Ноутбук', '16 ГБ RAM, 512 ГБ SSD', 65000, 10, 'шт', 'Москва']));
            $writer->addRow(Row::fromValues(['Разработка сайта', 'Корпоративный сайт, CMS', 150000, null, 'проект', 'Все регионы']));
            $writer->close();

            return response()->download($path, 'TenderFinder-catalog.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    public function import(Request $request, SpreadsheetImporter $importer): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], ...$this->fileRules()]);
        $file = $request->file('file');
        $items = $importer->read($file);
        $response = $this->change($user, $data['version'], function (PriceCatalog $catalog) use ($items, $file): void {
            $catalog->items()->delete();
            $catalog->items()->createMany($items);
            $catalog->filename = mb_substr(basename($file->getClientOriginalName()), 0, 255);
        });
        Log::info('catalog.imported', ['user_id' => $user->id, 'row_count' => count($items)]);

        return $response;
    }

    public function saveItem(Request $request, ?int $item = null): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], ...SpreadsheetImporter::rules()]);
        $version = $data['version'];
        unset($data['version']);
        $data['price'] = SpreadsheetImporter::decimal($data['price'] ?? null, 2, 'Цена');
        $data['quantity'] = SpreadsheetImporter::decimal($data['quantity'] ?? null, 3, 'Количество');

        return $this->change($user, $version, function (PriceCatalog $catalog) use ($item, $data): void {
            if ($item !== null) {
                $catalog->items()->findOrFail($item)->update($data);
            } else {
                abort_if($catalog->items()->count() >= SpreadsheetImporter::MAX_ROWS, 422, 'В каталоге допускается до 500 позиций.');
                $catalog->items()->create($data);
            }
        });
    }

    public function deleteItem(Request $request, int $item): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        return $this->change($user, $data['version'], fn (PriceCatalog $catalog) => $catalog->items()->findOrFail($item)->delete());
    }

    public function clear(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        return $this->change($user, $data['version'], function (PriceCatalog $catalog): void {
            $catalog->items()->delete();
            $catalog->filename = null;
        });
    }

    public function recommendations(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json($this->catalogs->recommendations($user));
    }

    public function show(Request $request, Tender $tender): Response
    {
        $user = $this->user($request);
        $this->authorizeTender($request, $user, $tender);

        return Inertia::render('CatalogComparison', [
            'catalog' => $this->catalogs->present($user), 'comparison' => $this->catalogs->comparison($user, $tender),
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'canonical_url' => $tender->canonical_url],
            'teamId' => $request->query('team_id'),
        ]);
    }

    public function specification(Request $request, Tender $tender, SpreadsheetImporter $importer): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeTender($request, $user, $tender);
        $data = $request->validate([...$this->comparisonRules(), ...$this->fileRules(), 'complete' => ['required', 'boolean']]);
        $file = $request->file('file');
        $rows = $importer->read($file);

        return $this->changeComparison($request, $tender, $data, function (PriceCatalogComparison $saved) use ($rows, $data, $file): void {
            $saved->requirements = $rows;
            $saved->complete = $data['complete'];
            $saved->filename = mb_substr(basename($file->getClientOriginalName()), 0, 255);
            $saved->reviews = [];
        });
    }

    public function clearSpecification(Request $request, Tender $tender): JsonResponse
    {
        $this->user($request);
        $data = $request->validate($this->comparisonRules());

        return $this->changeComparison($request, $tender, $data, function (PriceCatalogComparison $saved): void {
            $saved->requirements = null;
            $saved->complete = false;
            $saved->filename = null;
            $saved->reviews = [];
        });
    }

    public function review(Request $request, Tender $tender, CatalogMatcher $matcher): JsonResponse
    {
        $this->user($request);
        $data = $request->validate([...$this->comparisonRules(),
            'index' => ['required', 'integer', 'min:0', 'max:499'],
            'status' => ['required', Rule::in(['matched', 'uncertain', 'missing', 'auto'])],
            'item_id' => ['nullable', 'integer', 'min:1', 'required_if:status,matched'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->changeComparison($request, $tender, $data, function (PriceCatalogComparison $saved, User $user, Tender $current) use ($data, $matcher): void {
            $view = $this->catalogs->comparison($user, $current);
            $row = $view['rows'][$data['index']] ?? null;
            abort_if($row === null, 422, 'Позиция отсутствует в текущей спецификации.');
            $item = null;
            if (! empty($data['item_id']) && in_array($data['status'], ['matched', 'uncertain'], true)) {
                $catalog = $this->catalogs->catalog($user);
                abort_if($catalog === null, 404);
                $item = $catalog->items()->findOrFail($data['item_id']);
            }
            if ($data['status'] === 'matched' && $item !== null) {
                $checks = $matcher->checks($row['requirement'], $item->toArray());
                if ($row['stock_check'] !== null) {
                    $checks[] = $row['stock_check'];
                }
                abort_if(count(array_filter($checks, fn (array $check) => $check['state'] !== 'ok')) > 0 && blank($data['note'] ?? null),
                    422, 'Есть отличия или неизвестные условия. Укажите, почему вы подтверждаете соответствие.');
            }
            $reviews = $saved->reviews ?? [];
            if ($data['status'] === 'auto') {
                unset($reviews[$data['index']]);
            } else {
                $reviews[$data['index']] = ['status' => $data['status'], 'item_id' => $item?->id,
                    'note' => $data['note'] ?? null, 'catalog_version' => (int) $data['catalog_version'],
                    'tender_hash' => $data['tender_hash']];
            }
            $saved->reviews = $reviews;
        });
    }

    private function user(Request $request, bool $premium = true): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_if($premium && ! $this->catalogs->enabled($user), 403, 'Каталог доступен при действующем Pro или Ultra.');

        return $user;
    }

    private function authorizeTender(Request $request, User $user, Tender $tender): void
    {
        $team = app(TeamWorkspaceService::class)->context($request);
        if ($team !== null) {
            $shared = DB::table('tender_query_matches')->where('tender_id', $tender->id)
                ->whereIn('search_query_id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'))->exists();
            if ($shared) {
                return;
            }
        }
        app(TenderWorkService::class)->authorize($user, $tender, $team);
    }

    /** @param Closure(PriceCatalog): mixed $change */
    private function change(User $user, int $version, Closure $change): JsonResponse
    {
        DB::transaction(function () use ($user, $version, $change): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->catalogs->enabled($locked), 403);
            $catalog = PriceCatalog::query()->firstOrCreate(['user_id' => $locked->id]);
            abort_if($catalog->version !== $version, 409, 'Прайс изменён в другой вкладке. Обновите страницу.');
            $change($catalog);
            $catalog->version++;
            $catalog->save();
        });

        return response()->json(['catalog' => $this->catalogs->present($user)]);
    }

    /** @param array<string, mixed> $data
     * @param  Closure(PriceCatalogComparison, User, Tender): void  $change
     */
    private function changeComparison(Request $request, Tender $tender, array $data, Closure $change): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeTender($request, $user, $tender);
        DB::transaction(function () use ($request, $user, $tender, $data, $change): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->catalogs->enabled($locked), 403);
            $current = Tender::query()->lockForUpdate()->findOrFail($tender->id);
            $this->authorizeTender($request, $locked, $current);
            $view = $this->catalogs->comparison($locked, $current);
            abort_if($view['version'] !== (int) $data['version'] || $view['catalog_version'] !== (int) $data['catalog_version'] || $view['tender_hash'] !== $data['tender_hash'],
                409, 'Прайс, спецификация или тендер изменились. Обновите страницу и проверьте сравнение.');
            $saved = PriceCatalogComparison::query()->firstOrCreate(['user_id' => $locked->id, 'tender_id' => $current->id]);
            $change($saved, $locked, $current);
            $saved->version++;
            $saved->save();
        });

        return response()->json(['comparison' => $this->catalogs->comparison($user, $tender->refresh())]);
    }

    /** @return array<string, list<string>> */
    private function fileRules(): array
    {
        return ['file' => ['required', 'file', 'max:5120', 'extensions:xlsx']];
    }

    /** @return array<string, list<string>> */
    private function comparisonRules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'catalog_version' => ['required', 'integer', 'min:0'],
            'tender_hash' => ['required', 'string', 'size:64']];
    }
}
