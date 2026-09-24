// Mobile nav toggle
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('navToggle');
  var navLinks = document.querySelector('.nav-links');

  if (!toggle || !navLinks) return;

  toggle.addEventListener('click', function () {
    var open = navLinks.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });

  navLinks.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      navLinks.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });
});

// Admin sidebar toggle -- two buttons sharing the same job, shown one at a
// time by breakpoint: a "desktop" one beside the HealthQueue logo (collapses
// the sidebar to an icon rail, remembered in localStorage across page loads)
// and a "mobile" one in the topbar (opens/closes the off-canvas drawer --
// it has to live outside the sidebar itself since the drawer is translated
// off-screen when closed, so a trigger placed inside it would be unreachable).
document.addEventListener('DOMContentLoaded', function () {
  var toggles = document.querySelectorAll('.admin-sidebar-toggle');
  var sidebar = document.getElementById('adminSidebar');
  var scrim = document.getElementById('adminSidebarScrim');

  if (!toggles.length || !sidebar) return;

  var mobileQuery = window.matchMedia('(max-width: 980px)');

  function setAriaExpanded(expanded) {
    toggles.forEach(function (btn) { btn.setAttribute('aria-expanded', String(expanded)); });
  }

  function setOpen(open) {
    sidebar.classList.toggle('is-open', open);
    if (scrim) scrim.classList.toggle('is-open', open);
    setAriaExpanded(open);
  }

  function setCollapsed(collapsed) {
    sidebar.classList.toggle('is-collapsed', collapsed);
    setAriaExpanded(!collapsed);
    try { localStorage.setItem('hqSidebarCollapsed', String(collapsed)); } catch (e) { /* storage unavailable -- ignore */ }
  }

  // Sync both buttons' aria-expanded with whatever state the inline
  // flash-prevention script (in header.php) already applied before this ran.
  if (!mobileQuery.matches) {
    setAriaExpanded(!sidebar.classList.contains('is-collapsed'));
  }

  toggles.forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      if (mobileQuery.matches) {
        setOpen(!sidebar.classList.contains('is-open'));
      } else {
        setCollapsed(!sidebar.classList.contains('is-collapsed'));
      }
    });
  });

  if (scrim) {
    scrim.addEventListener('click', function () { setOpen(false); });
  }

  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () { setOpen(false); });
  });
});

// Generic modal open/close: any [data-modal-open="id"] trigger shows the
// element with that id, any [data-modal-close] (or a click on the overlay
// itself) closes its containing .modal-overlay, and Escape closes whichever
// modal is currently open. Visibility is driven purely by the "is-open"
// class (see style.css) so opening/closing always animates smoothly --
// centered modals fade+scale, .modal-drawer ones slide in from the right.
window.hqOpenModal = function (modal) {
  if (!modal) return;
  modal.classList.add('is-open');
  var firstInput = modal.querySelector('input, select, textarea');
  if (firstInput) firstInput.focus();
};

window.hqCloseModal = function (modal) {
  if (modal) modal.classList.remove('is-open');
};

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      window.hqOpenModal(document.getElementById(trigger.getAttribute('data-modal-open')));
    });
  });

  document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) window.hqCloseModal(overlay);
    });
    overlay.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () { window.hqCloseModal(overlay); });
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal-overlay.is-open').forEach(window.hqCloseModal);
  });
});

// Confirm-before-submit: a button with [data-confirm-modal="modalId"] opens
// that modal instead of submitting its form directly. The modal's own
// [data-confirm-submit] button then submits whichever form the trigger
// belonged to -- replaces native confirm() dialogs with the app's own
// modal style. One shared modal can serve many buttons on the same page.
//
// A modal field tagged [data-confirm-field="formFieldName"] has its value
// copied into the matching hidden input (same "name") on the target form
// just before submitting -- e.g. a reason-for-cancellation select. If the
// chosen <option> has data-other="true", the value instead comes from
// whichever field carries [data-confirm-field-for="formFieldName"] (a
// free-text "please specify" input revealed only for that option).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-confirm-modal]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var modal = document.getElementById(trigger.getAttribute('data-confirm-modal'));
      if (!modal) return;
      var form = trigger.closest('form');
      var confirmBtn = modal.querySelector('[data-confirm-submit]');
      if (confirmBtn) {
        confirmBtn.onclick = function () {
          if (!form) return;
          modal.querySelectorAll('[data-confirm-field]').forEach(function (field) {
            var name = field.getAttribute('data-confirm-field');
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) return;
            var value = field.value;
            var selectedOption = field.tagName === 'SELECT' ? field.options[field.selectedIndex] : null;
            if (selectedOption && selectedOption.getAttribute('data-other') === 'true') {
              var otherField = modal.querySelector('[data-confirm-field-for="' + name + '"]');
              value = otherField ? otherField.value : '';
            }
            input.value = value;
          });
          form.submit();
        };
      }
      window.hqOpenModal(modal);
    });
  });
});

// Lightweight month-grid calendar picker (no external library). Renders
// into a container holding .calendar-month-label / [data-nav] / .calendar-days
// markup, writes the chosen date (YYYY-MM-DD) into hiddenInput, and disables
// any day before today. Used by the booking modals' date field.
//
// options.isEnabled(iso) -> false greys out a date (e.g. no physician hours);
// options.noteFor(iso) -> text such as "Fully booked" shown as a tooltip;
// options.onMonthChange(year, monthIndex) fires when the shown month changes.
// Returns { render, clear } so callers can refresh after loading data.
window.hqInitCalendar = function (container, hiddenInput, onSelect, options) {
  if (!container || !hiddenInput) return null;
  options = options || {};

  var monthLabel = container.querySelector('.calendar-month-label');
  var daysEl = container.querySelector('.calendar-days');
  var prevBtn = container.querySelector('[data-nav="-1"]');
  var nextBtn = container.querySelector('[data-nav="1"]');
  var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var viewYear = today.getFullYear();
  var viewMonth = today.getMonth();
  var selectedDate = hiddenInput.value || null;

  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function toIso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

  function render() {
    monthLabel.textContent = monthNames[viewMonth] + ' ' + viewYear;
    daysEl.innerHTML = '';

    var firstWeekday = new Date(viewYear, viewMonth, 1).getDay();
    var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

    for (var i = 0; i < firstWeekday; i++) {
      var blank = document.createElement('span');
      blank.className = 'calendar-day-blank';
      daysEl.appendChild(blank);
    }

    for (let d = 1; d <= daysInMonth; d++) {
      let iso = toIso(viewYear, viewMonth, d);
      let cellDate = new Date(viewYear, viewMonth, d);
      let btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'calendar-day';
      btn.textContent = String(d);

      if (cellDate.getTime() < today.getTime() || (options.isEnabled && !options.isEnabled(iso))) {
        btn.disabled = true;
      }
      var note = options.noteFor ? options.noteFor(iso) : '';
      if (note) {
        btn.title = note;
        btn.classList.add('is-full');
        btn.setAttribute('aria-label', d + ' — ' + note);
      }
      if (cellDate.getTime() === today.getTime()) {
        btn.classList.add('is-today');
      }
      if (iso === selectedDate) {
        btn.classList.add('is-selected');
      }

      btn.addEventListener('click', function () {
        selectedDate = iso;
        hiddenInput.value = iso;
        render();
        if (onSelect) onSelect(iso);
      });

      daysEl.appendChild(btn);
    }

    if (prevBtn) prevBtn.disabled = (viewYear === today.getFullYear() && viewMonth === today.getMonth());
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      viewMonth--;
      if (viewMonth < 0) { viewMonth = 11; viewYear--; }
      render();
      if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      viewMonth++;
      if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      render();
      if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);
    });
  }

  render();
  if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);

  return {
    render: render,
    clear: function () { selectedDate = null; hiddenInput.value = ''; render(); }
  };
};

// Notification rows: tap a row to expand its message; tick checkboxes to
// choose which ones to delete ("Select all" + "Delete selected").
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.notification-list').forEach(function (list) {
    var form = list.closest('form');
    if (!form) return;
    var boxes = Array.prototype.slice.call(list.querySelectorAll('.notif-check'));
    var selectAll = form.querySelector('.notif-select-all');
    var deleteBtn = form.querySelector('.notification-delete-selected');
    var countEl = form.querySelector('.notification-selection-count');

    function update() {
      var checked = boxes.filter(function (box) { return box.checked; }).length;
      boxes.forEach(function (box) { box.closest('.notification-row').classList.toggle('is-selected', box.checked); });
      if (countEl) countEl.textContent = String(checked);
      if (deleteBtn) deleteBtn.disabled = checked === 0;
      if (selectAll) {
        selectAll.checked = checked > 0 && checked === boxes.length;
        selectAll.indeterminate = checked > 0 && checked < boxes.length;
      }
    }

    boxes.forEach(function (box) { box.addEventListener('change', update); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
        update();
      });
    }

    // Clicking the row (but not its checkbox or buttons) opens it: the full
    // message plus a "Delete notification" button for just that one.
    list.querySelectorAll('.notification-row').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('.notif-check-wrap, .notif-row-actions')) return;
        var open = row.classList.toggle('is-open');
        var msg = row.querySelector('.notif-message');
        if (msg) msg.classList.toggle('expanded', open);
      });
    });

    list.querySelectorAll('[data-delete-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete this notification?')) return;
        // Only this one -- untick anything else so it isn't deleted too.
        boxes.forEach(function (box) { box.checked = false; });
        [['notification_ids[]', btn.getAttribute('data-delete-id')], ['form_type', 'delete_selected']].forEach(function (pair) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = pair[0];
          input.value = pair[1];
          form.appendChild(input);
        });
        form.submit();
      });
    });

    if (deleteBtn) {
      deleteBtn.addEventListener('click', function (e) {
        var checked = boxes.filter(function (box) { return box.checked; }).length;
        if (!checked || !confirm('Delete ' + checked + ' selected notification' + (checked === 1 ? '' : 's') + '?')) {
          e.preventDefault();
        }
      });
    }

    update();
  });
});
