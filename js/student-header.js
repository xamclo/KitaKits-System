/* =========================================
   STUDENT HEADER - BELL UNREAD COUNTER
   Loaded only by Student Dashboard pages that include
   includes/student_header_actions.php.

   The bell is now a passive unread counter: no dropdown, no
   click handler and no notification list. Messages are read
   from the existing Dashboard notifications card and the
   Notifications page, which are unchanged.

   It only refreshes the number from the existing `notifications`
   table (scoped to the signed-in student) so the badge stays
   accurate when a new notification arrives or one is read.
   ========================================= */

(function () {
    "use strict";

    var API = "api/notifications.php";
    var POLL_MS = 30000;

    var count = document.getElementById("shBellCount");
    if (!count) return;

    function setBadge(total) {
        if (total > 0) {
            count.style.display = "block";
            count.textContent = total > 9 ? "9+" : total;
        } else {
            count.style.display = "none";
        }
    }

    function refresh() {
        var xhr = new XMLHttpRequest();
        xhr.open("GET", API + "?action=summary&t=" + Date.now(), true);
        xhr.onload = function () {
            if (xhr.status !== 200) return;

            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
            if (!data || typeof data.unread_count !== "number") return;

            setBadge(data.unread_count);
        };
        xhr.send();
    }

    refresh();
    setInterval(refresh, POLL_MS);
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) refresh();
    });
})();
