<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'پنل تیکت‌ها') – {{ config('app.name') }}</title>

    <link rel="icon" type="image/png" href="{{ asset('amptrace-logo.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --green: #16a34a;
            --green-bg: #dcfce7;
            --amber: #d97706;
            --amber-bg: #fef3c7;
            --red: #dc2626;
            --red-bg: #fee2e2;
            --violet: #7c3aed;
            --violet-bg: #ede9fe;
            --sky: #0369a1;
            --sky-bg: #e0f2fe;
        }

        body {
            font-family: "Vazirmatn", system-ui, -apple-system, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            min-height: 100vh;
        }

        header.topbar {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #fff;
            padding: 18px 28px;
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 8px rgba(15, 23, 42, .25);
        }

        header.topbar .brand { font-weight: 800; font-size: 1.15rem; display: flex; align-items: center; gap: 10px; }
        header.topbar .brand img { width: 34px; height: 34px; border-radius: 9px; object-fit: cover; box-shadow: 0 1px 4px rgba(0,0,0,.35); }

        header.topbar nav a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: .9rem;
            padding: 8px 14px;
            border-radius: 8px;
            transition: background .15s, color .15s;
        }
        header.topbar nav a:hover { background: rgba(255,255,255,.1); color: #fff; }

        main { max-width: 1120px; margin: 28px auto; padding: 0 20px 60px; }

        .page-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 18px; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 22px; }

        .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px; text-decoration: none; color: var(--text); transition: transform .15s, box-shadow .15s; }
        .stat:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15,23,42,.08); }
        .stat .value { font-size: 1.5rem; font-weight: 800; }
        .stat .label { font-size: .82rem; color: var(--muted); }
        .stat.active { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary) inset; }
        .stat .value.color-open { color: var(--sky); }
        .stat .value.color-progress { color: var(--amber); }
        .stat .value.color-resolved { color: var(--violet); }
        .stat .value.color-closed { color: var(--green); }

        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .toolbar form { display: flex; gap: 8px; flex: 1; max-width: 420px; }
        .toolbar input[type="search"] { flex: 1; padding: 9px 14px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; background: var(--surface); }
        .toolbar input[type="search"]:focus { outline: none; border-color: var(--primary); }
        .toolbar button { padding: 9px 18px; border: none; border-radius: 10px; background: var(--primary); color: #fff; font-family: inherit; font-weight: 600; cursor: pointer; }
        .toolbar button:hover { background: var(--primary-dark); }

        .ticket-list { display: flex; flex-direction: column; gap: 12px; }

        .ticket-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            transition: box-shadow .15s;
        }
        .ticket-card:hover { box-shadow: 0 6px 18px rgba(15,23,42,.07); }

        .ticket-card .info { flex: 1; min-width: 240px; }
        .ticket-card .num { font-size: .78rem; color: var(--muted); font-family: ui-monospace, monospace; margin-bottom: 2px; }
        .ticket-card .title { font-weight: 700; margin-bottom: 6px; }
        .ticket-card .meta { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: .8rem; color: var(--muted); }

        .badge { display: inline-flex; align-items: center; gap: 5px; font-size: .75rem; font-weight: 600; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
        .badge.open { background: var(--sky-bg); color: var(--sky); }
        .badge.progress { background: var(--amber-bg); color: var(--amber); }
        .badge.resolved { background: var(--violet-bg); color: var(--violet); }
        .badge.closed { background: var(--green-bg); color: var(--green); }
        .badge.priority-low { background: #f1f5f9; color: var(--muted); }
        .badge.priority-normal { background: #e0f2fe; color: #0369a1; }
        .badge.priority-high { background: var(--amber-bg); color: var(--amber); }
        .badge.priority-critical { background: var(--red-bg); color: var(--red); }
        .badge.neutral { background: #f1f5f9; color: var(--muted); }

        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; cursor: pointer;
            border: none; border-radius: 10px;
            padding: 9px 16px; font-family: inherit; font-weight: 600; font-size: .85rem;
            transition: background .15s;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-ghost { background: #f1f5f9; color: var(--text); }
        .btn-ghost:hover { background: #e2e8f0; }
        .btn-danger { background: var(--red); color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 5px 11px; font-size: .76rem; border-radius: 8px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: .9rem; font-weight: 600; }
        .alert.success { background: var(--green-bg); color: var(--green); }
        .alert.error { background: var(--red-bg); color: var(--red); }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 18px; }
        @media (max-width: 760px) { .form-grid { grid-template-columns: 1fr; } }
        .field label { display: block; font-size: .82rem; color: var(--muted); margin-bottom: 6px; font-weight: 600; }
        .field input[type="text"], .field input[type="number"], .field select, .field textarea {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border); border-radius: 10px;
            font-family: inherit; background: var(--surface);
        }
        .field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--primary); }
        .field .hint { font-size: .75rem; color: var(--muted); margin-top: 4px; }
        .field.full { grid-column: 1 / -1; }
        .check-row { display: flex; align-items: center; gap: 9px; }
        .check-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); }
        .check-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 8px; background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }

        .table { width: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .table th, .table td { padding: 11px 14px; text-align: right; border-bottom: 1px solid var(--border); font-size: .86rem; vertical-align: middle; }
        .table th { background: #f8fafc; font-weight: 700; color: var(--muted); }
        .table tr:last-child td { border-bottom: none; }
        .table .actions { display: flex; gap: 8px; justify-content: flex-end; }
        .table-wrap { overflow-x: auto; }
        .mono { direction: ltr; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .76rem; }
        .muted { color: var(--muted); font-size: .76rem; }

        .empty { text-align: center; color: var(--muted); padding: 50px 0; }
        .empty .big { font-size: 2.2rem; }

        /* detail page */
        .back { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 18px; }
        .grid-detail { display: grid; grid-template-columns: 1.3fr 1fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .grid-detail { grid-template-columns: 1fr; } }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 20px; }
        .card h2 { font-size: 1.05rem; font-weight: 800; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .card h2 small { color: var(--muted); font-weight: 400; font-size: .78rem; }

        .kv { display: grid; grid-template-columns: 130px 1fr; gap: 6px 14px; font-size: .9rem; }
        .kv dt { color: var(--muted); }
        .kv dd { font-weight: 600; overflow-wrap: anywhere; }

        .pre-block {
            background: #0f172a; color: #e2e8f0;
            border-radius: 10px; padding: 14px 16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .78rem; line-height: 1.6;
            overflow-x: auto; white-space: pre-wrap;
            margin-top: 14px; direction: ltr; text-align: left;
        }

        .notes { margin-top: 16px; border-top: 1px dashed var(--border); padding-top: 12px; }
        .note { margin-bottom: 14px; }
        .note .q { font-weight: 700; color: var(--primary); font-size: .88rem; }
        .note .a { color: var(--text); font-size: .9rem; white-space: pre-wrap; }
        .note .no { color: var(--muted); font-style: italic; }

        /* timeline */
        .timeline { position: relative; padding: 4px 0; }
        .timeline::before { content: ""; position: absolute; right: 15px; top: 6px; bottom: 6px; width: 2px; background: var(--border); }
        .timeline .entry { position: relative; padding-right: 44px; padding-bottom: 20px; }
        .timeline .entry:last-child { padding-bottom: 0; }
        .timeline .icon {
            position: absolute; right: 0; top: 0;
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem; border: 2px solid var(--surface);
            box-shadow: 0 0 0 1px var(--border);
        }
        .timeline .entry.created .icon { background: var(--sky-bg); }
        .timeline .entry.status .icon { background: var(--amber-bg); }
        .timeline .entry.resolution .icon { background: var(--violet-bg); }
        .timeline .entry.answer .icon { background: var(--violet-bg); }
        .timeline .entry.assigned .icon { background: var(--red-bg); }
        .timeline .entry.closed .icon { background: var(--green-bg); }

        .timeline .entry .head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .timeline .entry .title { font-weight: 700; font-size: .92rem; }
        .timeline .entry .time {
            display: inline-flex; align-items: center;
            font-family: "Vazirmatn", system-ui, sans-serif;
            font-size: .74rem; font-weight: 500; color: #475569;
            background: #f8fafc; border: 1px solid var(--border);
            border-radius: 7px; padding: 2px 8px; direction: rtl;
            white-space: nowrap;
        }
        .timeline .entry .desc { font-size: .85rem; color: var(--muted); margin-top: 3px; white-space: pre-wrap; }
        .timeline .entry .chg { margin-top: 6px; display: flex; gap: 6px; align-items: center; font-size: .8rem; flex-wrap: wrap; }
        .timeline .entry .arrow { color: var(--muted); }
        .chip { background: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; padding: 2px 9px; font-size: .78rem; font-weight: 600; }
        .chip.actor { color: var(--primary); }

        /* report page */
        .report-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
        .user-box {
            background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
            padding: 22px 16px 16px; text-align: center; transition: box-shadow .15s;
        }
        .user-box:hover { box-shadow: 0 6px 18px rgba(15,23,42,.07); }
        .avatar-wrap { position: relative; display: inline-block; margin-bottom: 10px; }
        .avatar { width: 76px; height: 76px; border-radius: 50%; object-fit: cover; border: 3px solid var(--surface); box-shadow: 0 0 0 2px var(--border); }
        .avatar.placeholder { display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 1.7rem; }
        .upload-btn {
            position: absolute; bottom: 0; left: 0;
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--primary); color: #fff; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: .72rem; box-shadow: 0 1px 4px rgba(0,0,0,.3);
            transition: background .15s;
        }
        .upload-btn:hover { background: var(--primary-dark); }
        .upload-btn input { display: none; }
        .user-box .name { font-weight: 700; font-size: .95rem; }
        .user-box .role { font-size: .78rem; color: var(--muted); margin-bottom: 12px; }
        .user-box .counts { display: flex; gap: 8px; }
        .count-item { flex: 1; background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 8px 4px 6px; }
        .count-item .n { font-size: 1.2rem; font-weight: 800; }
        .count-item .n.open { color: var(--sky); }
        .count-item .n.progress { color: var(--amber); }
        .count-item .n.closed { color: var(--green); }
        .count-item .l { font-size: .7rem; color: var(--muted); }

        footer { text-align: center; color: var(--muted); font-size: .78rem; padding: 20px; }
    </style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <img src="{{ asset('amptrace-logo.png') }}" alt="{{ config('app.name') }}">
        پنل تیکت‌های {{ config('app.name') }}
    </div>
    <nav>
        <a href="{{ route('panel.index') }}">📋 لیست تیکت‌ها</a>
        <a href="{{ route('panel.sessions.index') }}">🧩 سشن‌ها</a>
        <a href="{{ route('panel.report') }}">📊 گزارش</a>
        <a href="{{ route('panel.users.index') }}">👥 کاربران</a>
    </nav>
</header>

<main>
    @yield('content')
</main>

<footer>پنل مشاهده تیکت‌ها – {{ config('app.name') }} © {{ date('Y') }}</footer>

</body>
</html>
