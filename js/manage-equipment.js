/**
 * Manage Equipment - table rendering, live search and Add/Edit modals.
 *
 * Talks to admin/api/equipment.php over fetch() and re-renders the table
 * from the rows it sends back, so the list always reflects the database.
 */
(function () {
    "use strict";

    var API = "api/equipment.php";
    var UPLOAD_BASE = "../uploads/";

    var tableBody = document.getElementById("eqTableBody");
    var countEl = document.getElementById("eqCount");
    var searchInput = document.getElementById("eqSearch");
    var toast = document.getElementById("eqToast");

    var addModal = document.getElementById("addModal");
    var editModal = document.getElementById("editModal");
    var addForm = document.getElementById("addForm");
    var editForm = document.getElementById("editForm");
    var addAlert = document.getElementById("addAlert");
    var editAlert = document.getElementById("editAlert");
    var addPreview = document.getElementById("addImagePreview");
    var editPreview = document.getElementById("editImagePreview");

    var toastTimer = null;

    /* ---------------------------------------------------------
       Helpers
    --------------------------------------------------------- */
    function esc(value) {
        var div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    function peso(value) {
        var n = Number(value);
        return "₱" + (isNaN(n) ? "0.00" : n.toFixed(2));
    }

    function condClass(condition) {
        var known = { "Excellent": "excellent", "Good": "good", "Fair": "fair", "Poor": "poor" };
        return "eq-cond--" + (known[condition] || "none");
    }

    function showToast(message, isError) {
        if (!toast) { return; }

        toast.classList.toggle("eq-toast--error", !!isError);
        toast.innerHTML =
            '<i class="fa-solid ' + (isError ? "fa-circle-exclamation" : "fa-circle-check") + '"></i>' +
            "<span>" + esc(message) + "</span>";

        // Force a reflow so the transition replays on repeat calls.
        void toast.offsetWidth;
        toast.classList.add("is-visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.classList.remove("is-visible");
        }, 3200);
    }

    function setAlert(el, message, isError) {
        if (!el) { return; }

        if (!message) {
            el.classList.remove("is-visible");
            el.textContent = "";
            return;
        }

        el.textContent = message;
        el.classList.remove("eq-alert--error", "eq-alert--success");
        el.classList.add(isError ? "eq-alert--error" : "eq-alert--success");
        el.classList.add("is-visible");
    }

    function openModal(modal) {
        if (modal) { modal.classList.add("is-open"); }
        document.body.style.overflow = "hidden";
    }

    function closeModal(modal) {
        if (modal) { modal.classList.remove("is-open"); }
        document.body.style.overflow = "";
    }

    /* ---------------------------------------------------------
       Render the table from database rows
    --------------------------------------------------------- */
    function renderRows(rows) {
        if (!rows.length) {
            tableBody.innerHTML =
                '<tr><td colspan="7">' +
                '<div class="eq-empty">' +
                '<i class="fa-solid fa-box-open"></i>' +
                "<h3>No equipment found</h3>" +
                "<p>Try a different search, or add new equipment.</p>" +
                "</div></td></tr>";
            return;
        }

        var html = "";

        rows.forEach(function (row) {
            var available = Number(row.available_quantity) || 0;
            var availClass = available === 0 ? "eq-avail--none" : "";

            var photo = row.equipment_image
                ? '<img class="eq-thumb" src="' + esc(UPLOAD_BASE + row.equipment_image) + '" alt="' + esc(row.equipment_name) + '">'
                : '<span class="eq-thumb eq-thumb--empty"><i class="fa-solid fa-image"></i></span>';

            // High-value items carry an auto-generated asset code. Show it
            // next to the name so existing codes stay visible without a
            // separate column.
            var assetLine = "";
            if (Number(row.requires_school_approval) === 1 && row.asset_code) {
                assetLine = '<div class="eq-asset-code">' + esc(row.asset_code) + "</div>";
            }

            html +=
                "<tr>" +
                "<td>" + photo + "</td>" +
                "<td>" +
                '<div class="eq-name">' + esc(row.equipment_name) + "</div>" +
                assetLine +
                (row.description ? '<div class="eq-desc">' + esc(row.description) + "</div>" : "") +
                "</td>" +
                "<td><span class='eq-pill'>" + esc(row.category) + "</span></td>" +
                '<td><span class="eq-price">' + peso(row.rental_price) + "</span></td>" +
                "<td><span class='" + condClass(row.equipment_condition) + "'>" +
                esc(row.equipment_condition || "-") + "</span></td>" +
                '<td><span class="eq-avail ' + availClass + '">' + available + "</span></td>" +
                "<td>" +
                '<div class="eq-actions">' +
                '<button type="button" class="eq-btn eq-btn--edit" data-id="' + row.equipment_id + '">Edit</button>' +
                '<button type="button" class="eq-btn eq-btn--delete" data-id="' + row.equipment_id + '">Delete</button>' +
                "</div></td>" +
                "</tr>";
        });

        tableBody.innerHTML = html;
    }

    function loadTable() {
        var term = searchInput ? searchInput.value.trim() : "";

        fetch(API + "?action=list&search=" + encodeURIComponent(term), {
            credentials: "same-origin"
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message || "Could not load equipment.", true);
                    return;
                }

                var rows = data.equipment || [];
                renderRows(rows);

                if (countEl) {
                    var n = rows.length;
                    countEl.textContent = n + (n === 1 ? " item" : " items") +
                        (term ? " matching \u201C" + term + "\u201D" : " in your catalogue");
                }
            })
            .catch(function () {
                showToast("Network error while loading equipment.", true);
            });
    }

    /* ---------------------------------------------------------
       Modal open / close
    --------------------------------------------------------- */
    document.addEventListener("click", function (event) {
        var target = event.target;

        if (target.closest("#eqAddBtn")) {
            setAlert(addAlert, "");
            if (addForm) { addForm.reset(); }
            if (addPreview) { addPreview.innerHTML = '<i class="fa-solid fa-image"></i>'; }
            openModal(addModal);
            return;
        }

        if (target.closest("[data-close]")) {
            closeModal(target.closest(".eq-modal"));
            return;
        }

        // Clicking the dark backdrop cancels.
        if (target.classList && target.classList.contains("eq-modal")) {
            closeModal(target);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal(addModal);
            closeModal(editModal);
        }
    });

    /* Debounced live search */
    if (searchInput) {
        var timer = null;
        searchInput.addEventListener("input", function () {
            clearTimeout(timer);
            timer = setTimeout(loadTable, 250);
        });
    }

    /* Image preview before upload */
    function wirePreview(fileInput, previewEl) {
        if (!fileInput || !previewEl) { return; }

        fileInput.addEventListener("change", function () {
            var file = fileInput.files && fileInput.files[0];

            if (!file) {
                previewEl.innerHTML = '<i class="fa-solid fa-image"></i>';
                return;
            }

            var reader = new FileReader();
            reader.onload = function (e) {
                previewEl.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
        });
    }

    wirePreview(document.getElementById("addImage"), addPreview);
    wirePreview(document.getElementById("editImage"), editPreview);

    /* ---------------------------------------------------------
       High-value checkbox + description counter
    --------------------------------------------------------- */
    var DESCRIPTION_MAX = 300;

    function toggleHvFields(form) {
        var checkbox = form.querySelector("[name='requires_school_approval']");
        var hvBlock = form.querySelector(".eq-hv-fields");

        if (!checkbox || !hvBlock) { return; }

        var checked = checkbox.checked;
        hvBlock.style.display = checked ? "" : "none";
        hvBlock.setAttribute("aria-hidden", checked ? "false" : "true");

        // When the block is hidden its inputs are not required.
        var inputs = hvBlock.querySelectorAll("input, select, textarea");
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].required = checked;
        }
    }

    function wireDescCounter(textarea, counterEl) {
        if (!textarea || !counterEl) { return; }

        function update() {
            var len = textarea.value.length;
            var remaining = DESCRIPTION_MAX - len;

            counterEl.textContent = len + " / " + DESCRIPTION_MAX;
            counterEl.classList.toggle("is-near-limit", remaining <= 30 && remaining > 0);
            counterEl.classList.toggle("is-over-limit", remaining < 0);
        }

        textarea.addEventListener("input", update);
        update();
    }

    wireDescCounter(document.getElementById("addDescription"),
                    document.getElementById("addDescCounter"));
    wireDescCounter(document.getElementById("editDescription"),
                    document.getElementById("editDescCounter"));

    // Toggle the high-value block whenever the checkbox changes.
    [addForm, editForm].forEach(function (form) {
        if (!form) { return; }
        var checkbox = form.querySelector("[name='requires_school_approval']");
        if (checkbox) {
            checkbox.addEventListener("change", function () { toggleHvFields(form); });
        }
    });

    /* ---------------------------------------------------------
       Frontend validation before the request is sent.
       Mirrors the backend checks so the user gets instant feedback
       instead of a round-trip to the server.
    --------------------------------------------------------- */
    function validateForm(form) {
        var name = (form.elements.equipment_name && form.elements.equipment_name.value || "").trim();
        var cat  = (form.elements.category && form.elements.category.value || "").trim();
        var price = (form.elements.rental_price && form.elements.rental_price.value);
        var units = (form.elements.available_quantity && form.elements.available_quantity.value);
        var desc  = (form.elements.description && form.elements.description.value || "");

        clearFieldErrors(form);

        if (!name) { return fieldError(form, "equipment_name", "Equipment name is required."); }
        if (name.length > 150) { return fieldError(form, "equipment_name", "Name is too long (max 150)."); }
        if (!cat) { return fieldError(form, "category", "Category is required."); }
        if (price === "" || isNaN(price) || Number(price) < 0) {
            return fieldError(form, "rental_price", "Please enter a valid rental price.");
        }
        if (units === "" || !/^\d+$/.test(units) || Number(units) < 1) {
            return fieldError(form, "available_quantity", "Total units must be a whole number of at least 1.");
        }
        if (desc.length > DESCRIPTION_MAX) {
            return fieldError(form, "description", "Description is too long. Maximum is " + DESCRIPTION_MAX + " characters.");
        }

        var checkbox = form.querySelector("[name='requires_school_approval']");
        if (checkbox && checkbox.checked) {
            var declared = (form.elements.declared_value && form.elements.declared_value.value || "").trim();
            var accessories = (form.elements.accessories && form.elements.accessories.value || "").trim();

            if (declared === "" || isNaN(declared) || Number(declared) <= 0) {
                return fieldError(form, "declared_value", "Declared Value is required for high-value items.");
            }
            if (accessories === "") {
                return fieldError(form, "accessories", "Included Accessories is required for high-value items.");
            }
        }

        return null;
    }

    function fieldError(form, name, message) {
        var el = form.elements[name];
        if (!el) { return message; }

        var field = el.closest(".eq-field");
        if (field) {
            field.classList.add("eq-field--error");

            var existing = field.querySelector(".eq-field__error");
            if (!existing) {
                existing = document.createElement("span");
                existing.className = "eq-field__error";
                field.appendChild(existing);
            }
            existing.textContent = message;
        }
        return message;
    }

    function clearFieldErrors(form) {
        var fields = form.querySelectorAll(".eq-field--error");
        for (var i = 0; i < fields.length; i++) {
            fields[i].classList.remove("eq-field--error");
            var err = fields[i].querySelector(".eq-field__error");
            if (err) { err.remove(); }
        }
    }

    /* ---------------------------------------------------------
       Submit helpers
    --------------------------------------------------------- */
    function postUrlEncoded(action, id) {
        return fetch(API, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "action=" + action + "&id=" + encodeURIComponent(id),
            credentials: "same-origin"
        }).then(function (res) { return res.json(); });
    }

    function send(form, action, alertEl, onDone) {
        var button = form.querySelector("button[type='submit']");
        var original = button ? button.textContent : "";

        if (button) {
            button.disabled = true;
            button.textContent = "Saving\u2026";
        }

        // The API dispatches on an "action" field, which no form carries,
        // so it is appended to the multipart body here.
        var body = new FormData(form);
        body.append("action", action);

        fetch(API, {
            method: "POST",
            body: body,
            credentials: "same-origin"
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (button) {
                    button.disabled = false;
                    button.textContent = original;
                }

                if (!data.ok) {
                    setAlert(alertEl, data.message || "Something went wrong.", true);
                    return;
                }

                setAlert(alertEl, "", false);
                onDone(data);
            })
            .catch(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = original;
                }
                setAlert(alertEl, "Network error. Please try again.", true);
            });
    }

    if (addForm) {
        addForm.addEventListener("submit", function (event) {
            event.preventDefault();

            var validationError = validateForm(addForm);
            if (validationError) {
                setAlert(addAlert, validationError, true);
                return;
            }

            send(addForm, "create", addAlert, function (data) {
                closeModal(addModal);
                addForm.reset();
                if (addPreview) { addPreview.innerHTML = '<i class="fa-solid fa-image"></i>'; }
                toggleHvFields(addForm);
                showToast(data.message, false);
                loadTable();
            });
        });
    }

    if (editForm) {
        editForm.addEventListener("submit", function (event) {
            event.preventDefault();

            var validationError = validateForm(editForm);
            if (validationError) {
                setAlert(editAlert, validationError, true);
                return;
            }

            send(editForm, "update", editAlert, function (data) {
                closeModal(editModal);
                showToast(data.message, false);
                loadTable();
            });
        });
    }

    /* Edit + Delete, delegated so they work on re-rendered rows */
    document.addEventListener("click", function (event) {
        var editBtn = event.target.closest(".eq-btn--edit");
        var delBtn = event.target.closest(".eq-btn--delete");

        if (editBtn) {
            var id = editBtn.getAttribute("data-id");

            postUrlEncoded("fetch", id)
                .then(function (data) {
                    if (!data.ok) {
                        showToast(data.message, true);
                        return;
                    }

                    var eq = data.equipment;

                    editForm.reset();
                    editForm.elements.id.value = eq.equipment_id;
                    editForm.elements.equipment_name.value = eq.equipment_name || "";
                    editForm.elements.category.value = eq.category || "";
                    editForm.elements.description.value = eq.description || "";
                    editForm.elements.rental_price.value = eq.rental_price || "";
                    editForm.elements.available_quantity.value = eq.available_quantity || 1;
                    editForm.elements.equipment_condition.value = eq.equipment_condition || "Good";
                    editForm.elements.status.value = eq.status || "available";

                    // High-value classification. Guarded because the fetch
                    // payload predates these fields.
                    if (editForm.elements.requires_school_approval) {
                        editForm.elements.requires_school_approval.checked =
                            Number(eq.requires_school_approval) === 1;
                    }
                    if (editForm.elements.declared_value) {
                        editForm.elements.declared_value.value = eq.declared_value || "";
                    }
                    if (editForm.elements.accessories) {
                        editForm.elements.accessories.value = eq.accessories || "";
                    }

                    // Asset codes are generated by the backend, so the field
                    // is no longer editable. The value is read-only info.

                    editPreview.innerHTML = eq.equipment_image
                        ? '<img src="' + esc(UPLOAD_BASE + eq.equipment_image) + '" alt="Current photo">'
                        : '<i class="fa-solid fa-image"></i>';

                    // Show/hide the high-value required-fields block and
                    // refresh the description counter now that the form is
                    // populated.
                    toggleHvFields(editForm);
                    var descCounter = document.getElementById("editDescCounter");
                    if (descCounter) {
                        var len = (eq.description || "").length;
                        descCounter.textContent = len + " / " + DESCRIPTION_MAX;
                        descCounter.classList.toggle("is-near-limit",
                            (DESCRIPTION_MAX - len) <= 30 && (DESCRIPTION_MAX - len) > 0);
                        descCounter.classList.toggle("is-over-limit", len > DESCRIPTION_MAX);
                    }

                    setAlert(editAlert, "");
                    openModal(editModal);
                })
                .catch(function () {
                    showToast("Network error while loading equipment.", true);
                });
        }

        if (delBtn) {
            var delId = delBtn.getAttribute("data-id");

            // Explain that history is preserved rather than promising a
            // hard delete, because an item with rental history is archived.
            if (!window.confirm(
                "Delete this equipment?\n\n"
                + "If it has never been reserved it is removed completely.\n"
                + "If it has rental history it is archived instead, so past "
                + "reservations and transactions are kept.\n\n"
                + "An item with an active or pending rental cannot be deleted."
            )) {
                return;
            }

            postUrlEncoded("delete", delId)
                .then(function (data) {
                    showToast(data.message, !data.ok);
                    loadTable();
                })
                .catch(function () {
                    showToast("Network error while deleting.", true);
                });
        }
    });

    /* Initial paint */
    loadTable();
})();

