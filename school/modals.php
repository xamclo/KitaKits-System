<?php
/**
 * The three School Management modals, shared by every /school page.
 *
 * Each is a separate dialog that opens on demand - none of them is
 * rendered permanently underneath the dashboard.
 *
 *   smApprovalModal  - Transaction Approval   (module 1)
 *   smReturnModal    - Return Verification    (module 3)
 *   smHistoryModal   - Transaction History    (module 5)
 *
 * Bodies are filled by js/school-modals.js from the API.
 */
?>

<!-- ===================== TRANSACTION WITNESSING ===================== -->
<div class="sm-modal" id="smApprovalModal" role="dialog" aria-modal="true" aria-labelledby="smApprovalTitle">
    <div class="sm-modal-card">
        <div class="sm-modal-head">
            <h3 id="smApprovalTitle">Transaction Witnessing</h3>
            <button class="sm-modal-close" type="button" onclick="smModal.close('smApprovalModal')" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="sm-modal-body">
            <div class="sm-section-title">Transaction Information</div>
            <dl class="sm-kv" id="smApprTxn"></dl>

            <div class="sm-section-title">Equipment Details</div>
            <div class="sm-equip-hero" id="smApprEquip"></div>
            <dl class="sm-kv" id="smApprEquipKv"></dl>

            <div class="sm-section-title">Accessories Included</div>
            <div id="smApprAccessories"></div>

            <div class="sm-section-title">Student Information</div>
            <div class="sm-person" id="smApprStudent"></div>

            <div class="sm-section-title">Transaction Details</div>
            <dl class="sm-kv" id="smApprDetail"></dl>

            <div class="sm-section-title">Witness Note</div>
            <label for="smApprNotes" class="sm-sub">Required when flagging a transaction.</label>
            <textarea id="smApprNotes" rows="3" style="width:100%;padding:10px;border:1px solid #e6e9f2;border-radius:9px;font-family:inherit;font-size:13px;"
                      placeholder="Add a witness note or a flag reason..."></textarea>
        </div>

        <div class="sm-modal-foot">
            <button class="sm-btn-outline" type="button" id="smApprRejectBtn"
                    style="padding:10px 18px;">Flag Transaction</button>
            <button class="sm-link-btn" type="button" id="smApprApproveBtn"
                    style="padding:10px 22px;">Confirm as Witness</button>
        </div>
    </div>
</div>


<!-- ===================== RETURN VERIFICATION ===================== -->
<div class="sm-modal" id="smReturnModal" role="dialog" aria-modal="true" aria-labelledby="smReturnTitle">
    <div class="sm-modal-card">
        <div class="sm-modal-head">
            <h3 id="smReturnTitle">Return Verification</h3>
            <button class="sm-modal-close" type="button" onclick="smModal.close('smReturnModal')" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="sm-modal-body">
            <div class="sm-section-title">Transaction Information</div>
            <dl class="sm-kv" id="smRetTxn"></dl>

            <div class="sm-section-title">Equipment Details</div>
            <div class="sm-equip-hero" id="smRetEquip"></div>
            <dl class="sm-kv" id="smRetEquipKv"></dl>

            <div class="sm-section-title">Return Condition</div>
            <div id="smRetCondition"></div>

            <div class="sm-section-title">Photos</div>
            <div id="smRetEvidence"></div>

            <div class="sm-section-title">Owner Inspection Notes</div>
            <div id="smRetOwnerNotes" class="sm-sub"></div>

            <div class="sm-section-title">Verification Notes</div>
            <textarea id="smRetNotes" rows="3" style="width:100%;padding:10px;border:1px solid #e6e9f2;border-radius:9px;font-family:inherit;font-size:13px;"
                      placeholder="Record your verification observation..."></textarea>
        </div>

        <div class="sm-modal-foot">
            <button class="sm-btn-outline" type="button" id="smRetIssueBtn"
                    style="padding:10px 18px;">Flag Issue</button>
            <button class="sm-link-btn" type="button" id="smRetConfirmBtn"
                    style="padding:10px 22px;">Verify Return Record</button>
        </div>
    </div>
</div>


<!-- ===================== TRANSACTION HISTORY ===================== -->
<div class="sm-modal" id="smHistoryModal" role="dialog" aria-modal="true" aria-labelledby="smHistoryTitle">
    <div class="sm-modal-card">
        <div class="sm-modal-head">
            <h3 id="smHistoryTitle">Transaction History</h3>
            <button class="sm-modal-close" type="button" onclick="smModal.close('smHistoryModal')" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="sm-modal-body">
            <div class="sm-section-title">Timeline</div>
            <ul class="sm-timeline" id="smHistTimeline"></ul>

            <div class="sm-section-title">Transaction Details</div>
            <div class="sm-equip-hero" id="smHistEquip"></div>
            <dl class="sm-kv" id="smHistDetail"></dl>
        </div>

        <div class="sm-modal-foot">
            <button class="sm-btn-cancel" type="button" onclick="smModal.close('smHistoryModal')">Close</button>
        </div>
    </div>
</div>
