@extends('panel.layout')

@section('title', $user ? 'ویرایش کاربر' : 'کاربر جدید')

@php
    $isEdit = $user !== null;
    $defaultCategories = $isEdit ? ($user->categories_covered ?? []) : null;
    $oldOr = function (string $key, bool $default): bool {
        $sent = old($key);
        return $sent !== null ? in_array($sent, ['1', 'on', true], true) : $default;
    };
@endphp

@section('content')
    <a class="back btn btn-ghost" href="{{ route('panel.users.index') }}">→ بازگشت به کاربران</a>
    <div class="page-title">{{ $isEdit ? '✏️ ویرایش کاربر' : '＋ کاربر جدید' }}</div>

    @if ($errors->any())
        <div class="alert error">⚠️ لطفاً خطاهای زیر را رفع کنید و دوباره ثبت کنید.</div>
    @endif

    <div class="card" style="max-width:860px">
        <form method="POST" action="{{ $isEdit ? route('panel.users.update', $user) : route('panel.users.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-grid">
                <div class="field">
                    <label for="name">نام کامل *</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name ?? '') }}" required>
                    @error('name')<div class="hint" style="color:var(--red)">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="username">یوزرنیم تلگرام *</label>
                    <input type="text" id="username" name="username" value="{{ old('username', $user->username ?? '') }}" placeholder="example_user" required>
                    <div class="hint">بدون علامت @ — در صورت نیاز می‌توان از پیشوند بات استفاده کرد.</div>
                    @error('username')<div class="hint" style="color:var(--red)">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="role">نقش *</label>
                    <select id="role" name="role" required>
                        @foreach ($roles as $key => $label)
                            <option value="{{ $key }}" @selected(old('role', $user->role ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role')<div class="hint" style="color:var(--red)">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="telegram_id">تلگرام آیدی (اختیاری)</label>
                    <input type="number" id="telegram_id" name="telegram_id" value="{{ old('telegram_id', $user->telegram_id ?? '') }}">
                </div>

                <div class="field full">
                    <label>پوشش دسته‌های مشکل</label>
                    <div class="check-row" style="margin-bottom:10px">
                        <input type="checkbox" id="cover_all_categories" name="cover_all_categories" value="1"
                               @checked($oldOr('cover_all_categories', $defaultCategories === null))>
                        <label for="cover_all_categories" style="margin:0; font-weight:600">همه دسته‌ها (بدون محدودیت)</label>
                    </div>
                    <div class="check-list">
                        @foreach (config('incident.categories') as $key => $definition)
                            @php
                                $isSelected = in_array($key, old('categories', $defaultCategories ?? []), true);
                            @endphp
                            <div class="check-row">
                                <input type="checkbox" id="cat-{{ $key }}" name="categories[]" value="{{ $key }}" @checked($isSelected)>
                                <label for="cat-{{ $key }}" style="margin:0">{{ $definition['label'] }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="hint">«همه دسته‌ها» یعنی این کاربر می‌تواند مسئول هر تیکتی شود؛ اگر دسته‌ای انتخاب نکنید، همان رفتار اعمال می‌شود.</div>
                    @error('categories')<div class="hint" style="color:var(--red)">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label>امکانات</label>
                    <div class="check-row"><input type="checkbox" name="can_be_assignee" value="1" id="can_be_assignee" @checked($oldOr('can_be_assignee', $user->can_be_assignee ?? true))><label for="can_be_assignee">قابل تخصیص به عنوان مسئول</label></div>
                    <div class="check-row"><input type="checkbox" name="is_default_assignee" value="1" id="is_default_assignee" @checked($oldOr('is_default_assignee', $user->is_default_assignee ?? false))><label for="is_default_assignee">مسئول پیش‌فرض (وقتی AI نتواند تشخیص دهد)</label></div>
                    <div class="check-row"><input type="checkbox" name="auto_assign_on_mention" value="1" id="auto_assign_on_mention" @checked($oldOr('auto_assign_on_mention', $user->auto_assign_on_mention ?? false))><label for="auto_assign_on_mention">تخصیص خودکار هنگام ذکر نام در گزارش</label></div>
                    <div class="check-row"><input type="checkbox" name="is_active" value="1" id="is_active" @checked($oldOr('is_active', $user->is_active ?? true))><label for="is_active">فعال</label></div>
                </div>

                <div class="field">
                    <label for="assignee_chat_id">چت آیدی بات دوم (اختیاری)</label>
                    <input type="number" id="assignee_chat_id" name="assignee_chat_id" value="{{ old('assignee_chat_id', $user->assignee_chat_id ?? '') }}" placeholder="به‌صورت خودکار با /start ثبت می‌شود">
                    <div class="hint">برای ارسال خودکار تیکت‌ها کافی است کاربر بات را /start کند.</div>
                    @error('assignee_chat_id')<div class="hint" style="color:var(--red)">{{ $message }}</div>@enderror
                </div>

                @if ($isEdit)
                    <div class="field full">
                        <label>عکس پروفایل</label>
                        <div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap">
                            <div class="avatar-wrap" style="margin-bottom:0">
                                @if ($user->avatarUrl())
                                    <img class="avatar" src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}">
                                @else
                                    <div class="avatar placeholder">👤</div>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('panel.users.avatar', $user) }}" enctype="multipart/form-data">
                                @csrf
                                <input type="file" name="avatar" accept="image/*" required>
                                <div class="hint" style="color:var(--red)">{{ $errors->first('avatar') }}</div>
                                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px">📷 بارگذاری عکس</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">💾 {{ $isEdit ? 'ذخیره تغییرات' : 'ایجاد کاربر' }}</button>
                <a href="{{ route('panel.users.index') }}" class="btn btn-ghost">انصراف</a>
            </div>
        </form>
    </div>
@endsection