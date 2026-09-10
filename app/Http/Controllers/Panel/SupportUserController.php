<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\IncidentTicket;
use App\Models\SupportUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SupportUserController extends Controller
{
    public const ROLES = [
        'ceo' => 'مدیرعامل',
        'project_manager' => 'مدیر پروژه',
        'technical_lead' => 'هد تکنیکال',
        'backend_developer' => 'توسعه‌دهنده بک‌اند',
        'frontend_developer' => 'توسعه‌دهنده فرانت‌اند',
        'qa' => 'تضمین کیفیت',
        'devops' => 'دوآپس',
        'database_administrator' => 'مدیر دیتابیس',
        'support' => 'پشتیبانی',
    ];

    public function index(): View
    {
        $users = SupportUser::query()
            ->withCount(['assignedTickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ['open', 'in_progress'])])
            ->orderBy('id')
            ->get();

        return view('panel.users.index', [
            'users' => $users,
            'roles' => self::ROLES,
        ]);
    }

    public function create(): View
    {
        return view('panel.users.form', [
            'user' => null,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPayload($request);
        SupportUser::create($data);

        return redirect()->route('panel.users.index')->with('status', '✅ کاربر جدید ساخته شد.');
    }

    public function edit(SupportUser $user): View
    {
        return view('panel.users.form', [
            'user' => $user,
            'roles' => self::ROLES,
        ]);
    }

    public function update(Request $request, SupportUser $user): RedirectResponse
    {
        $data = $this->validatedPayload($request, $user);
        $user->update($data);

        return redirect()->route('panel.users.index')->with('status', '✅ کاربر به‌روزرسانی شد.');
    }

    public function destroy(SupportUser $user): RedirectResponse
    {
        IncidentTicket::query()->where('assignee_id', $user->id)->update(['assignee_id' => null]);
        $this->deleteAvatarFile($user);
        $user->delete();

        return redirect()->route('panel.users.index')->with('status', '🗑 کاربر حذف شد.');
    }

    /**
     * Accept an uploaded avatar image and persist it on the public disk. The
     * previous file (if any) is removed.
     */
    public function updateAvatar(Request $request, SupportUser $user): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('avatar');
        $path = $file->store('avatars', 'public');
        if ($path === false) {
            return back()->withErrors(['avatar' => 'بارگذاری عکس انجام نشد.'])->withInput();
        }

        $this->deleteAvatarFile($user);
        $user->update(['avatar_path' => basename($path)]);

        return back()->with('status', '✅ عکس پروفایل «'.$user->name.'» به‌روزرسانی شد.');
    }

    private function deleteAvatarFile(SupportUser $user): void
    {
        if ($user->avatar_path !== null && $user->avatar_path !== '') {
            Storage::disk('public')->delete('avatars/'.basename($user->avatar_path));
        }
    }

    /**
     * Validate the form and build the fillable payload, normalising the
     * username and resolving the covered-categories choice ("cover all" or no
     * selection stores null = covers everything).
     */
    private function validatedPayload(Request $request, ?SupportUser $user = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'username' => ['required', 'string', 'max:190', function ($attribute, $value, $fail) use ($user): void {
                $normalized = mb_strtolower(ltrim($value, '@'));
                $exists = SupportUser::query()
                    ->whereRaw('LOWER(username) = ?', [$normalized])
                    ->when($user !== null, fn ($query) => $query->whereKeyNot($user->id))
                    ->exists();
                if ($exists) {
                    $fail('این یوزرنیم قبلاً ثبت شده است.');
                }
            }],
            'role' => ['required', 'string', 'in:'.implode(',', array_keys(self::ROLES))],
            'telegram_id' => ['nullable', 'integer'],
            'assignee_chat_id' => ['nullable', 'integer'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', array_keys(config('incident.categories')))],
        ]);

        $selected = $validated['categories'] ?? [];
        $coverAll = $request->boolean('cover_all_categories') || $selected === [];

        return [
            'name' => $validated['name'],
            'username' => mb_strtolower(ltrim($validated['username'], '@')),
            'role' => $validated['role'],
            'telegram_id' => $validated['telegram_id'] ?? null,
            'assignee_chat_id' => $validated['assignee_chat_id'] ?? null,
            'categories_covered' => $coverAll ? null : array_values($selected),
            'can_be_assignee' => $request->boolean('can_be_assignee'),
            'is_default_assignee' => $request->boolean('is_default_assignee'),
            'auto_assign_on_mention' => $request->boolean('auto_assign_on_mention'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
