@extends('panel.layout')

@section('title', 'کاربران')

@section('content')
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:18px">
        <div class="page-title" style="margin-bottom:0">👥 کاربران تعریف‌شده</div>
        <a class="btn btn-primary" href="{{ route('panel.users.create') }}">＋ کاربر جدید</a>
    </div>

    @if (session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card" style="padding:0; border:none; box-shadow:none; background:transparent">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نام</th>
                    <th>یوزرنیم</th>
                    <th>نقش</th>
                    <th>پوشش دسته‌ها</th>
                    <th>تیکت‌های باز</th>
                    <th>گزینه‌ها</th>
                    <th>اقدامات</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td>
                            <b>{{ $user->name }}</b>
                            @if ($user->is_default_assignee)
                                <span class="badge priority-high">پیش‌فرض</span>
                            @endif
                            @if (! $user->is_active)
                                <span class="badge neutral">غیرفعال</span>
                            @endif
                        </td>
                        <td><code>@{{ $user->username }}</code></td>
                        <td>{{ $roles[$user->role] ?? $user->role }}</td>
                        <td style="font-size:.8rem; color:var(--muted)">
                            @if ($user->categories_covered === null)
                                همه دسته‌ها
                            @else
                                {{ $user->coveredCategoryLabelText() }}
                            @endif
                        </td>
                        <td>
                            @if ($user->open_tickets_count > 0)
                                <span class="badge progress">{{ $user->open_tickets_count }}</span>
                            @else
                                <span style="color:var(--muted)">۰</span>
                            @endif
                        </td>
                        <td style="font-size:.75rem; color:var(--muted)">
                            @if ($user->can_be_assignee)<span class="badge closed">مسئول</span>@endif
                            @if ($user->auto_assign_on_mention)<span class="badge neutral">ذکر خودکار</span>@endif
                            @if ($user->assignee_chat_id)چت: <code>{{ $user->assignee_chat_id }}</code>@endif
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('panel.users.edit', $user) }}">✏️ ویرایش</a>
                                <form method="POST" action="{{ route('panel.users.destroy', $user) }}"
                                      onsubmit="return confirm('حذف «{{ $user->name }}»؟ تیکت‌های باز او بدون مسئول می‌شوند.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">🗑 حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection