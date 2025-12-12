<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reader</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 0; }
        #toolbar { height: 56px; display:flex; align-items:center; justify-content:space-between; padding:0 16px; background:#f3f3f3; }
        #viewer { padding:20px; max-width:900px; margin:20px auto; background:white; min-height:70vh; box-shadow:0 0 8px rgba(0,0,0,0.06); }
        button { padding:8px 12px; }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<div id="toolbar">
    <div>
        <button id="prev">◀ Prev</button>
        <button id="next">Next ▶</button>
    </div>
    <div>Chapter: <span id="cur">0</span> / <span id="total">0</span></div>
</div>

<div id="viewer">Loading...</div>

<script>
    let bookId = {{ $bookId }};
    let index = 0;
    let total = 0;

    function loadChapter(i) {
        $.get(`/api/books/${bookId}/chapter`, { index: i })
            .done(function(res) {
                if (!res.success) {
                    $("#viewer").html("<b>Error:</b> " + (res.message || 'unknown'));
                    return;
                }
                $("#viewer").html(res.html);
                index = res.chapterIndex;
                total = res.totalChapters;
                $("#cur").text(index+1);
                $("#total").text(total);
            })
            .fail(function(xhr){
                $("#viewer").html("<b>Error:</b> " + xhr.responseJSON?.message ?? 'failed');
            });
    }

    $("#prev").on('click', function(){
        if (index > 0) loadChapter(index-1);
    });

    $("#next").on('click', function(){
        if (index + 1 < total) loadChapter(index+1);
    });

    // initial
    $(function(){ loadChapter(0); });
</script>
</body>
</html>
