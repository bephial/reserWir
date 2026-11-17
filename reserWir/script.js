// script.js - Interaktionen für Modals, Checkboxen und Buchungen

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Modal per Klick außerhalb des Fensters schließen
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});

// ESC Taste zum Schließen
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop').forEach(m => {
            m.style.display = 'none';
        });
        document.body.style.overflow = '';
    }
});

/**
 * Öffnet das Buchungsmodal ganz normal
 */
function openNewBookingModal() {
    openModal('modalNewBooking');
    setTimeout(() => {
        const teacherInput = document.getElementById('teacherCode');
        if (teacherInput && !teacherInput.value) {
            teacherInput.focus();
        }
    }, 100);
}

/**
 * Öffnet das Buchungsmodal vorausgefüllt aus einem Klick auf einen freien Slot
 */
function openNewBookingWithPrefill(itemKey, dateStr, periodNum) {
    // Item auswählen
    const itemSelect = document.getElementById('bookItemKey');
    if (itemSelect) {
        itemSelect.value = itemKey;
    }

    // Datum setzen
    const dateInput = document.getElementById('bookDate');
    if (dateInput) {
        dateInput.value = dateStr;
    }

    // Stunden-Checkboxen zurücksetzen und die angeklickte Stunde markieren
    selectPeriods([periodNum]);

    openModal('modalNewBooking');

    setTimeout(() => {
        const teacherInput = document.getElementById('teacherCode');
        if (teacherInput) {
            teacherInput.focus();
        }
    }, 100);
}

/**
 * Schnell-Auswahl für Schulstunden (1 bis 11)
 */
function selectPeriods(periodArray) {
    for (let i = 1; i <= 11; i++) {
        const chk = document.getElementById('checkPeriod' + i);
        if (chk) {
            chk.checked = periodArray.includes(i);
        }
    }
}

/**
 * Öffnet die Detailansicht einer gebuchten Stunde mit Stornierungsmöglichkeit
 */
function openBookingDetailModal(booking, itemName) {
    if (!booking) return;

    // Item-Name
    document.getElementById('detailItemName').textContent = itemName || (booking.item_type === 'room' ? 'Raum' : (booking.item_type === 'divider' ? 'Trenner' : 'Ressource'));

    // Datum & Stunde formatieren
    const dParts = booking.booking_date.split('-');
    const formattedDate = `${dParts[2]}.${dParts[1]}.${dParts[0]}`;
    document.getElementById('detailDateTime').textContent = `${formattedDate} • ${booking.period}. Stunde`;

    // Lehrkraft & Infos
    document.getElementById('detailTeacher').textContent = booking.teacher_code;
    document.getElementById('detailClassSubject').textContent = `${booking.class_name} • ${booking.subject}`;

    // Serie Info
    const seriesRow = document.getElementById('detailSeriesRow');
    const btnCancelSeries = document.getElementById('btnCancelSeries');

    if (booking.series_id) {
        seriesRow.style.display = 'flex';
        const sCount = booking.series_count || 'mehrere';
        const isWeek = (booking.series_type === 'week') || (parseInt(booking.series_days || 1) > 1);

        if (isWeek) {
            document.getElementById('detailSeriesInfo').textContent = `🔁 Wochenserie (insgesamt ${sCount} Termine über mehrere Wochen)`;
            if (btnCancelSeries) {
                btnCancelSeries.textContent = 'Gesamte Wochenserie stornieren';
                btnCancelSeries.style.display = 'inline-block';
            }
        } else {
            document.getElementById('detailSeriesInfo').textContent = `📅 Tagesserie (insgesamt ${sCount} Stunden am selben Tag)`;
            if (btnCancelSeries) {
                btnCancelSeries.textContent = 'Alle Stunden dieses Tages stornieren';
                btnCancelSeries.style.display = 'inline-block';
            }
        }
    } else {
        seriesRow.style.display = 'none';
        if (btnCancelSeries) {
            btnCancelSeries.style.display = 'none';
        }
    }

    // Berechtigung zum Stornieren prüfen: Eigene Buchung oder Administrator
    const canCancel = (typeof IS_USER_ADMIN !== 'undefined' && IS_USER_ADMIN) || 
                      (typeof CURRENT_TEACHER_CODE !== 'undefined' && CURRENT_TEACHER_CODE && CURRENT_TEACHER_CODE.toUpperCase() === booking.teacher_code.toUpperCase());

    const allowedContainer = document.getElementById('cancelAllowedContainer');
    const blockedNotice = document.getElementById('cancelBlockedNotice');
    const blockedTeacherCode = document.getElementById('blockedTeacherCode');

    if (canCancel) {
        if (allowedContainer) allowedContainer.style.display = 'block';
        if (blockedNotice) blockedNotice.style.display = 'none';
    } else {
        if (allowedContainer) allowedContainer.style.display = 'none';
        if (blockedNotice) {
            blockedNotice.style.display = 'block';
            if (blockedTeacherCode) blockedTeacherCode.textContent = booking.teacher_code;
        }
    }

    // Formular ID setzen
    document.getElementById('cancelBookingId').value = booking.id;

    openModal('modalBookingDetail');
}
