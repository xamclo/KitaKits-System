/* =========================================
   STUDENT - EQUIPMENT LIST FILTER & SORT
   Loaded only by student/equipment.php.

   Works on the cards already rendered from the database, so search,
   category and sort all operate on the SAME records and combine
   correctly. No Apply button: every control reacts immediately.
   ========================================= */

(function () {
    "use strict";

    var grid = document.getElementById("equipmentGrid");
    var search = document.getElementById("equipmentSearch");
    var categorySel = document.getElementById("categoryFilter");
    var sortSel = document.getElementById("sortFilter");
    if (!grid || !search || !categorySel || !sortSel) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll(".equipment-card"));
    var emptyMsg = grid.querySelector(".empty-equipment");

    function apply() {
        var term = search.value.trim().toLowerCase();
        var cat = categorySel.value;
        var visible = [];

        cards.forEach(function (card) {
            var name = (card.dataset.name || "").toLowerCase();
            var cardCat = card.dataset.category || "";

            var okSearch = term === "" || name.indexOf(term) !== -1;
            var okCat = cat === "" || cardCat === cat;

            if (okSearch && okCat) {
                card.style.display = "";
                visible.push(card);
            } else {
                card.style.display = "none";
            }
        });

        // Sort on the numeric data-* values, never on the formatted price text.
        var mode = sortSel.value;
        if (mode) {
            visible.sort(function (a, b) {
                if (mode === "price_low") {
                    return (+a.dataset.price) - (+b.dataset.price);
                }
                if (mode === "price_high") {
                    return (+b.dataset.price) - (+a.dataset.price);
                }
                if (mode === "quantity") {
                    return (+b.dataset.qty) - (+a.dataset.qty);
                }
                return 0;
            });

            // Re-append the same nodes in the new order (no duplication).
            visible.forEach(function (card) {
                grid.appendChild(card);
            });
        }

        if (emptyMsg) {
            emptyMsg.style.display = visible.length === 0 ? "" : "none";
        }
    }

    search.addEventListener("input", apply);
    categorySel.addEventListener("change", apply);
    sortSel.addEventListener("change", apply);

    // Respect deep links (e.g. equipment.php?category=Camera) and run once.
    apply();
})();
