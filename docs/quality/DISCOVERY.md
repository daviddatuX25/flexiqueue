# Discovery Checklist

Run the relevant section every time you open a file to work on it.
Any item that fails → add a new row to `ISSUES.md` before moving on.

---

## Controller (`app/Http/Controllers/`)

- [ ] Does it contain any Eloquent model queries (e.g. `Model::where()`, `Model::find()`)? → move to a Service
- [ ] Does it contain `DB::table()` or `DB::statement()` calls? → move to a Service
- [ ] Does it contain `Schema::hasTable()` or `Schema::hasColumn()` calls? → remove; trust migrations
- [ ] Is any logic block (5+ lines) copy-pasted across two or more methods? → extract to a Service method
- [ ] Does it build or transform a response array from a model manually? → extract to an API Resource (`app/Http/Resources/`)

## Service (`app/Services/`)

- [ ] Does it type-hint or reference `Illuminate\Http\Request` or `Response`? → that concern belongs in the Controller
- [ ] Does it instantiate another service with `new` instead of receiving it via constructor injection? → inject it
- [ ] Is a private method duplicated in a different service? → extract to a shared service or support class

## Model (`app/Models/`)

- [ ] Does `booted()` or any observer do file operations (`Storage::`, `File::`)? → move to a Listener
- [ ] Does any instance method call `self::where()`, `static::where()`, or any other query? → move to a Service
- [ ] Does any instance method update sibling rows (`static::where(...)->update(...)`)? → move to a Service
- [ ] Are there 5 or more accessor/getter methods all serving one concept? → extract to a value object in `app/Support/`

## Svelte Component (`resources/js/Components/`)

- [ ] Does it use `fetch()`, `axios`, or `$inertia.post/get` directly? → move API calls to the Page level
- [ ] Does it accept more than ~5 props? → consider splitting into smaller components
- [ ] Is any logic (computed values, event handlers) duplicated in another component? → extract a shared component

## Svelte Page (`resources/js/Pages/`)

- [ ] Does it use raw `fetch()` instead of `useForm()` or `router`? → replace with Inertia helpers
- [ ] Does it contain logic (computed values, conditional rendering blocks) that belongs in a child component? → extract it
- [ ] Does it pass `$page.props` deeply into children for non-auth/non-flash data? → pass as explicit named props

---

## Adding a new issue

1. Note: the file path, the checklist item that failed, and which principle in `PRINCIPLES.md` it violates.
2. Add a row to the `ISSUES.md` summary table: next `#`, severity, layer, file, short issue name, status `open`.
3. Add a detail section at the bottom of `ISSUES.md` with file path, severity, status, and concrete action steps.
