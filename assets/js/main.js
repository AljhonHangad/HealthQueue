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
window.hqInitCalendar = function (container, hiddenInput, onSelect) {
  if (!container || !hiddenInput) return;

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

      if (cellDate.getTime() < today.getTime()) {
        btn.disabled = true;
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
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      viewMonth++;
      if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      render();
    });
  }

  render();
};

// Notification rows: tap to expand the message in place, long-press to
// enter multi-select mode (mobile-app style) and delete the selected ones.
document.addEventListener('DOMContentLoaded', function () {
  var LONG_PRESS_MS = 500;

  document.querySelectorAll('.notification-list').forEach(function (list) {
    var form = list.closest('form');
    var bar = list.querySelector('.notification-selection-bar');
    var countEl = bar ? bar.querySelector('.notification-selection-count') : null;
    var deleteBtn = bar ? bar.querySelector('.notification-delete-selected') : null;
    var cancelBtn = bar ? bar.querySelector('.notification-cancel-selection') : null;
    var selected = new Set();

    function updateBar() {
      if (!bar) return;
      bar.hidden = selected.size === 0;
      if (countEl) countEl.textContent = String(selected.size);
    }

    function clearSelection() {
      selected.clear();
      list.querySelectorAll('.notification-row.is-selected').forEach(function (row) {
        row.classList.remove('is-selected');
      });
      updateBar();
    }

    function toggleSelect(row) {
      var id = row.getAttribute('data-id');
      if (selected.has(id)) {
        selected.delete(id);
        row.classList.remove('is-selected');
      } else {
        selected.add(id);
        row.classList.add('is-selected');
      }
      updateBar();
    }

    function toggleExpand(row) {
      var msg = row.querySelector('.notif-message');
      if (msg) msg.classList.toggle('expanded');
    }

    list.querySelectorAll('.notification-row').forEach(function (row) {
      var timer = null;
      var longPressed = false;

      function start() {
        longPressed = false;
        timer = setTimeout(function () {
          longPressed = true;
          if (navigator.vibrate) navigator.vibrate(15);
          toggleSelect(row);
        }, LONG_PRESS_MS);
      }
      function stopTimer() {
        clearTimeout(timer);
      }
      function end() {
        stopTimer();
        if (longPressed) return;
        if (selected.size > 0) {
          toggleSelect(row);
        } else {
          toggleExpand(row);
        }
      }

      row.addEventListener('mousedown', start);
      row.addEventListener('touchstart', start, { passive: true });
      row.addEventListener('mouseup', end);
      row.addEventListener('touchend', end);
      row.addEventListener('mouseleave', stopTimer);
      row.addEventListener('touchmove', stopTimer);
      row.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    });

    if (deleteBtn) {
      deleteBtn.addEventListener('click', function () {
        if (!form || selected.size === 0) return;
        if (!confirm('Delete ' + selected.size + ' selected notification(s)?')) return;
        selected.forEach(function (id) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'notification_ids[]';
          input.value = id;
          form.appendChild(input);
        });
        var typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'form_type';
        typeInput.value = 'delete_selected';
        form.appendChild(typeInput);
        form.submit();
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', clearSelection);
    }
  });
});
