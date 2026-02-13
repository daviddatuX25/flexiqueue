# Laravel 12 Patterns for Inertia Apps

## Table of Contents
- [Controller Pattern](#controller-pattern)
- [Service Classes](#service-classes)
- [Form Requests](#form-requests)
- [Eloquent Conventions](#eloquent-conventions)
- [Migration Patterns](#migration-patterns)
- [Middleware](#middleware)
- [Route Organization](#route-organization)

---

## Controller Pattern

Controllers are thin. They handle HTTP concerns and delegate to services.

### Returning Inertia Pages

```php
// app/Http/Controllers/StationController.php
namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Program;
use App\Services\StationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function __construct(
        private StationService $stationService
    ) {}
    
    public function index(): Response
    {
        return Inertia::render('Station/Index', [
            'stations' => Station::with('currentSession.token')->get(),
            'program' => Program::active()->first(),
        ]);
    }
    
    public function show(Station $station): Response
    {
        return Inertia::render('Station/Show', [
            'station' => $station->load('currentSession', 'waitingQueue'),
            'canCallNext' => $station->waiting_count > 0,
        ]);
    }
}
```

### Handling Form Submissions

```php
use App\Http\Requests\TransferSessionRequest;
use Illuminate\Http\RedirectResponse;

public function transfer(TransferSessionRequest $request, Session $session): RedirectResponse
{
    $this->sessionService->transfer(
        $session,
        $request->validated('target_station_id'),
        $request->validated('mode')
    );
    
    return redirect()
        ->route('station.show', $session->current_station_id)
        ->with('success', "Session transferred successfully");
}
```

### API-Style Returns (for AJAX within Inertia)

For actions that don't navigate (e.g., inline updates):

```php
public function callNext(Station $station): RedirectResponse
{
    $session = $this->stationService->callNext($station);
    
    // Redirect back to same page - Inertia will refresh props
    return back()->with('success', "Now serving: {$session->alias}");
}
```

---

## Service Classes

Business logic lives in services, not controllers.

### Service Structure

```php
// app/Services/SessionService.php
namespace App\Services;

use App\Models\Session;
use App\Models\Token;
use App\Models\ServiceTrack;
use App\Events\ClientBound;
use App\Events\SessionTransferred;
use Illuminate\Support\Facades\DB;

class SessionService
{
    public function __construct(
        private FlowEngine $flowEngine,
        private AuditLogger $auditLogger
    ) {}
    
    public function bind(string $tokenHash, int $trackId): Session
    {
        return DB::transaction(function () use ($tokenHash, $trackId) {
            $token = Token::where('hash', $tokenHash)->firstOrFail();
            $track = ServiceTrack::findOrFail($trackId);
            
            // Validate token is available
            if ($token->status !== 'available') {
                throw new TokenUnavailableException($token);
            }
            
            // Create session
            $session = Session::create([
                'token_id' => $token->id,
                'service_track_id' => $track->id,
                'alias' => $token->physical_id,
                'status' => 'waiting',
                'current_station_id' => $this->flowEngine->getFirstStation($track),
                'current_step_order' => 1,
            ]);
            
            // Update token status
            $token->update(['status' => 'in_use']);
            
            // Log transaction
            $this->auditLogger->log($session, 'bind', [
                'track_id' => $trackId,
            ]);
            
            // Broadcast event
            ClientBound::dispatch($session);
            
            return $session;
        });
    }
    
    public function transfer(Session $session, int $targetStationId, string $mode): Session
    {
        return DB::transaction(function () use ($session, $targetStationId, $mode) {
            $oldStationId = $session->current_station_id;
            
            $session->update([
                'status' => 'waiting',
                'current_station_id' => $targetStationId,
                'current_step_order' => $session->current_step_order + 1,
            ]);
            
            $this->auditLogger->log($session, 'transfer', [
                'from_station_id' => $oldStationId,
                'to_station_id' => $targetStationId,
                'mode' => $mode,
            ]);
            
            SessionTransferred::dispatch($session, $oldStationId, $targetStationId);
            
            return $session->fresh();
        });
    }
}
```

### Injecting Services

```php
// In controller constructor
public function __construct(
    private SessionService $sessionService,
    private StationService $stationService
) {}

// Laravel auto-resolves from container
```

---

## Form Requests

Validation and authorization in dedicated classes.

### Creating Form Requests

```bash
php artisan make:request BindSessionRequest
```

### Form Request Structure

```php
// app/Http/Requests/BindSessionRequest.php
namespace App\Http\Requests;

use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BindSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check user has permission
        return $this->user()->can('create', Session::class);
    }
    
    public function rules(): array
    {
        return [
            'token_hash' => [
                'required',
                'string',
                'size:64', // SHA-256 hash
                Rule::exists('tokens', 'hash')->where('status', 'available'),
            ],
            'track_id' => [
                'required',
                'integer',
                Rule::exists('service_tracks', 'id')->where('program_id', $this->activeProgram()->id),
            ],
        ];
    }
    
    public function messages(): array
    {
        return [
            'token_hash.exists' => 'This token is unavailable or not registered.',
            'track_id.exists' => 'Invalid track for the current program.',
        ];
    }
    
    protected function activeProgram()
    {
        return \App\Models\Program::active()->firstOrFail();
    }
}
```

### Using in Controller

```php
public function bind(BindSessionRequest $request): RedirectResponse
{
    // Validation already passed - $request->validated() is safe
    $session = $this->sessionService->bind(
        $request->validated('token_hash'),
        $request->validated('track_id')
    );
    
    return redirect()->route('triage.index')
        ->with('success', "Bound client to {$session->alias}");
}
```

---

## Eloquent Conventions

### Model Structure

```php
// app/Models/Session.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'token_id',
        'service_track_id',
        'alias',
        'status',
        'current_station_id',
        'current_step_order',
        'started_at',
        'completed_at',
    ];
    
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'current_step_order' => 'integer',
    ];
    
    // Relationships
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
    
    public function track(): BelongsTo
    {
        return $this->belongsTo(ServiceTrack::class, 'service_track_id');
    }
    
    public function currentStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'current_station_id');
    }
    
    public function transactionLogs(): HasMany
    {
        return $this->hasMany(TransactionLog::class);
    }
    
    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'serving']);
    }
    
    public function scopeAtStation($query, int $stationId)
    {
        return $query->where('current_station_id', $stationId);
    }
    
    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }
    
    // Accessors
    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, ['waiting', 'serving']);
    }
}
```

### Query Patterns

```php
// Eager loading to avoid N+1
$stations = Station::with(['currentSession.token', 'waitingSessions'])->get();

// Using scopes
$waitingSessions = Session::active()
    ->atStation($stationId)
    ->waiting()
    ->orderBy('created_at')
    ->get();

// Aggregates
$waitingCount = Session::atStation($stationId)->waiting()->count();
```

---

## Migration Patterns

### Standard Migration

```php
// database/migrations/2026_02_14_000001_create_sessions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_track_id')->constrained();
            $table->foreignId('current_station_id')->nullable()->constrained('stations');
            $table->string('alias', 10)->index();
            $table->enum('status', ['waiting', 'serving', 'completed', 'cancelled', 'no_show'])
                  ->default('waiting');
            $table->unsignedTinyInteger('current_step_order')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Composite indexes for common queries
            $table->index(['current_station_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
```

### Adding Columns

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('served_by_user_id')
                  ->nullable()
                  ->after('current_station_id')
                  ->constrained('users');
        });
    }
    
    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign(['served_by_user_id']);
            $table->dropColumn('served_by_user_id');
        });
    }
};
```

---

## Middleware

### Role-Based Access

```php
// app/Http/Middleware/EnsureRole.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            if ($request->wantsJson() || $request->header('X-Inertia')) {
                abort(403, 'Unauthorized');
            }
            return redirect()->route('login');
        }
        
        return $next($request);
    }
}
```

### Register in Kernel

```php
// bootstrap/app.php (Laravel 12)
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureRole::class,
    ]);
})
```

### Using in Routes

```php
Route::middleware(['auth', 'role:admin,supervisor'])->group(function () {
    Route::get('/admin/programs', [ProgramController::class, 'index']);
});

Route::middleware(['auth', 'role:staff,supervisor,admin'])->group(function () {
    Route::get('/station', [StationController::class, 'index']);
});
```

---

## Route Organization

### Web Routes with Inertia

```php
// routes/web.php
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', fn () => Inertia::render('Welcome'));

// Auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    
    // Staff routes
    Route::middleware('role:staff,supervisor,admin')->group(function () {
        Route::get('/triage', [TriageController::class, 'index'])->name('triage.index');
        Route::post('/triage/bind', [TriageController::class, 'bind'])->name('triage.bind');
        
        Route::get('/station', [StationController::class, 'index'])->name('station.index');
        Route::post('/station/{station}/call-next', [StationController::class, 'callNext']);
        Route::post('/sessions/{session}/transfer', [SessionController::class, 'transfer']);
        Route::post('/sessions/{session}/complete', [SessionController::class, 'complete']);
    });
    
    // Admin routes
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::resource('programs', ProgramController::class);
        Route::resource('stations', StationController::class);
        Route::resource('tokens', TokenController::class);
        Route::resource('users', UserController::class);
        
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/csv', [ReportController::class, 'exportCsv']);
    });
});

// Public display (no auth)
Route::get('/display', [DisplayController::class, 'index'])->name('display');
Route::get('/status/{tokenHash}', [DisplayController::class, 'checkStatus']);
```

### Route Model Binding

```php
// Automatic resolution
Route::get('/sessions/{session}', [SessionController::class, 'show']);
// Laravel resolves Session model by ID automatically

// Custom key
Route::get('/tokens/{token:hash}', [TokenController::class, 'show']);
// Resolves by 'hash' column instead of 'id'
```
