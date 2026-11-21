document.addEventListener("DOMContentLoaded", () => {
  const dateList = document.getElementById("dateList");
  const monthLabel = document.getElementById("monthLabel");
  const scheduleList = document.getElementById("scheduleList");
  const dateStrip = document.querySelector(".date-strip");
  const timetableCalendar = document.querySelector(".timetable-calendar");
  const filters = {
    className: document.getElementById("classFilter"),
    level: document.getElementById("levelFilter"),
    instructor: document.getElementById("instructorFilter"),
  };

  let schedules = [];

  let weekStartDate = new Date();
  weekStartDate.setHours(0, 0, 0, 0);
  let selectedDate = new Date(weekStartDate);

  const pad = (n) => String(n).padStart(2, "0");
  const iso = (d) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const timeText = (t) => {
    const [h, m] = t.split(":").map(Number);
    return `${pad(h)}.${pad(m)}`;
  };
  const isUpcomingSchedule = (item, now = new Date()) => {
    if (!item.date || !item.start) return false;
    const [h = 0, m = 0, s = 0] = item.start.split(":").map(Number);
    const startDateTime = new Date(
      `${item.date}T${pad(h)}:${pad(m)}:${pad(s)}+07:00`,
    );
    return startDateTime > now;
  };
  const monthText = (d) =>
    d.toLocaleDateString("en-US", { month: "long", year: "numeric" });
  const longDate = (d) =>
    d.toLocaleDateString("en-US", {
      weekday: "short",
      day: "2-digit",
      month: "short",
      year: "numeric",
    });

  async function loadTimetableSchedules() {
    try {
      const res = await fetch("../admin/booking-schedule.php");
      const json = await res.json();
      if (json.success && Array.isArray(json.data)) {
        schedules = json.data.map((item) => ({
          scheduleId: item.scheduleId,
          date: item.date,
          start: item.startTime,
          end: item.endTime,
          instructor: item.instructor,
          className: item.shortClass,
          category: item.className,
          level: item.level,
          type: item.classType,
          price: item.price,
          slot: Number(item.bookedCount),
          capacity: Number(item.memberSlot),
        }));
      }
    } catch (err) {
      console.error("Failed to load timetable schedules:", err);
    }
    renderDates();
    renderSchedules();
  }

  function renderDates() {
    dateList.innerHTML = "";
    monthLabel.textContent = monthText(weekStartDate);

    for (let i = 0; i < 7; i++) {
      const date = new Date(weekStartDate);
      date.setDate(weekStartDate.getDate() + i);

      const button = document.createElement("button");
      button.type = "button";
      button.className =
        "date-card" + (iso(date) === iso(selectedDate) ? " selected" : "");
      button.setAttribute(
        "aria-pressed",
        iso(date) === iso(selectedDate) ? "true" : "false",
      );
      button.innerHTML = `
        <span class="date-day">${date.toLocaleDateString("en-US", { weekday: "short" })}</span>
        <strong class="date-number">${date.getDate()}</strong>
      `;

      button.addEventListener("click", () => {
        if (iso(date) === iso(selectedDate)) return;
        selectedDate = new Date(date);
        renderDates();
        renderSchedules();
        moveDateStripToSelectedDay(true);
      });

      dateList.appendChild(button);
    }
  }

  function buildBookingUrl(item) {
    const params = new URLSearchParams({
      scheduleId: item.scheduleId,
      date: item.date,
      start: item.start,
      end: item.end,
      instructor: item.instructor,
      className: item.className,
      category: item.category,
      level: item.level,
      type: item.type,
      price: item.price,
      slot: item.slot,
      capacity: item.capacity,
    });
    return `booking-detail.php?${params.toString()}`;
  }

  function renderSchedules() {
    if (
      dateStrip &&
      timetableCalendar &&
      dateStrip.parentElement !== timetableCalendar
    ) {
      timetableCalendar.appendChild(dateStrip);
    }
    scheduleList.innerHTML = "";
    const now = new Date();

    for (let i = 0; i < 7; i++) {
      const date = new Date(weekStartDate);
      date.setDate(weekStartDate.getDate() + i);
      const dayItems = schedules
        .filter((s) => s.date === iso(date))
        .filter((s) => isUpcomingSchedule(s, now))
        .filter(
          (s) =>
            !filters.className.value || s.className === filters.className.value,
        )
        .filter((s) => !filters.level.value || s.level === filters.level.value)
        .filter(
          (s) =>
            !filters.instructor.value ||
            s.instructor === filters.instructor.value,
        )
        .sort((a, b) => a.start.localeCompare(b.start));

      const section = document.createElement("section");
      section.className = "schedule-day schedule-day-enter";
      section.dataset.date = iso(date);
      const heading = document.createElement("h3");
      heading.className = "schedule-day-heading";
      heading.innerHTML = `<span>${longDate(date)}</span><small>${dayItems.length} ${dayItems.length === 1 ? "class" : "classes"}</small>`;
      section.appendChild(heading);

      if (!dayItems.length) {
        const empty = document.createElement("p");
        empty.className = "empty-schedule";
        empty.textContent = "No classes scheduled.";
        section.appendChild(empty);
      }

      dayItems.forEach((item) => {
        const full = item.slot >= item.capacity;
        const label = full ? "Fully Booked" : "Book Now";
        const card = document.createElement("article");
        card.className = "schedule-card";
        card.innerHTML = `
          <div class="schedule-time"><span>${timeText(item.start)} -</span><span>${timeText(item.end)} WIB</span></div>
          ${[
            ["Coach", item.instructor],
            ["Class", item.className],
            ["Category", item.category],
            ["Level", item.level],
            ["Slot", `${item.slot} / ${item.capacity}`],
          ]
            .map(
              ([title, value]) =>
                `<div class="schedule-detail"><span class="detail-label">${title}</span><span>${value}</span></div>`,
            )
            .join("")}
          <button class="schedule-action ${full ? "waitlist" : "book"}" type="button" ${full ? 'disabled aria-disabled="true"' : ""}>${label}</button>`;

        const actionBtn = card.querySelector("button");
        const slotValueEl = card.querySelector(
          ".schedule-detail:last-of-type span:last-child",
        );

        if (!full) {
          actionBtn.addEventListener("click", async () => {
            if (actionBtn.disabled) return;

            const originalText = actionBtn.textContent;
            actionBtn.disabled = true;
            actionBtn.textContent = "Checking...";

            try {
              const fd = new FormData();
              fd.append("action", "init_hold");
              fd.append("schedule_id", item.scheduleId);
              fd.append("date", item.date);
              fd.append("start", item.start);
              fd.append("end", item.end);
              fd.append("instructor", item.instructor);
              fd.append("className", item.className);
              fd.append("category", item.category);
              fd.append("level", item.level);
              fd.append("type", item.type);
              fd.append("capacity", item.capacity);
              fd.append("qty", "1");

              const res = await fetch("api-hold-slot.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin",
              });

              const rawText = await res.text();
              let data = {};
              try {
                data = JSON.parse(rawText);
              } catch (parseErr) {
                throw new Error(
                  "Server returned non-JSON: " + rawText.slice(0, 250),
                );
              }

              if (data.status === "unauthenticated") {
                window.location.href = buildBookingUrl(item);
                return;
              }

              if (data.status === "full") {
                alert(
                  "Oops, sorry! The last spot in your selected class has just been booked. Kindly choose another available class.",
                );
                item.slot = item.capacity;
                actionBtn.textContent = "Fully Booked";
                actionBtn.classList.remove("book");
                actionBtn.classList.add("waitlist");
                actionBtn.disabled = true;
                actionBtn.setAttribute("aria-disabled", "true");
                if (slotValueEl) {
                  slotValueEl.textContent = `${item.capacity} / ${item.capacity}`;
                }
                return;
              }

              if (data.status === "ok") {
                window.location.href = buildBookingUrl(item);
                return;
              }

              alert(data.message || "Unable to verify slot availability.");
              actionBtn.disabled = false;
              actionBtn.textContent = originalText;
            } catch (err) {
              console.error(err);
              alert(
                err.message ||
                  "Connection error while checking slot availability. Please try again.",
              );
              actionBtn.disabled = false;
              actionBtn.textContent = originalText;
            }
          });
        }

        section.appendChild(card);
      });

      scheduleList.appendChild(section);
    }
  }

  function moveDateStripToSelectedDay(animate = true) {
    const targetSection = scheduleList.querySelector(
      `[data-date="${iso(selectedDate)}"]`,
    );
    if (!dateStrip || !targetSection) return;

    const firstRect = dateStrip.getBoundingClientRect();
    targetSection.insertBefore(
      dateStrip,
      targetSection.querySelector(".schedule-day-heading"),
    );
    dateStrip.classList.add("date-strip--moved");

    const lastRect = dateStrip.getBoundingClientRect();

    const fixedHeader = document.querySelector(".site-header, .main-nav");
    let headerBottom = 0;

    if (fixedHeader) {
      const headerRect = fixedHeader.getBoundingClientRect();
      const headerPosition = window.getComputedStyle(fixedHeader).position;

      if (headerPosition === "fixed" || headerPosition === "sticky") {
        headerBottom = headerRect.bottom;
      }
    }

    const headerGap = 24;
    const visibleTop = headerBottom + headerGap;
    const scrollTarget = Math.max(
      0,
      window.scrollY + lastRect.top - visibleTop,
    );

    window.scrollTo({
      top: scrollTarget,
      behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
        ? "auto"
        : "smooth",
    });

    if (
      !animate ||
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
      dateStrip.style.transform = "";
      dateStrip.style.transition = "";
      return;
    }

    const deltaX = firstRect.left - lastRect.left;
    const deltaY = firstRect.top - lastRect.top;
    dateStrip.style.transition = "none";
    dateStrip.style.transform = `translate(${deltaX}px, ${deltaY}px)`;
    dateStrip.getBoundingClientRect();

    requestAnimationFrame(() => {
      dateStrip.style.transition = "transform .7s cubic-bezier(.22,.8,.25,1)";
      dateStrip.style.transform = "translate(0, 0)";
    });

    dateStrip.addEventListener("transitionend", function clearMove(event) {
      if (event.propertyName !== "transform") return;
      dateStrip.style.transition = "";
      dateStrip.style.transform = "";
      dateStrip.removeEventListener("transitionend", clearMove);
    });
  }

  document.getElementById("previousWeek").addEventListener("click", () => {
    weekStartDate.setDate(weekStartDate.getDate() - 7);
    selectedDate = new Date(weekStartDate);
    renderDates();
    renderSchedules();
    moveDateStripToSelectedDay(true);
  });
  document.getElementById("nextWeek").addEventListener("click", () => {
    weekStartDate.setDate(weekStartDate.getDate() + 7);
    selectedDate = new Date(weekStartDate);
    renderDates();
    renderSchedules();
    moveDateStripToSelectedDay(true);
  });
  Object.values(filters).forEach((select) =>
    select.addEventListener("change", () => {
      renderSchedules();
      moveDateStripToSelectedDay(false);
    }),
  );

  loadTimetableSchedules();

  setInterval(() => {
    const wasMoved =
      dateStrip && dateStrip.classList.contains("date-strip--moved");
    renderSchedules();
    if (wasMoved) {
      moveDateStripToSelectedDay(false);
    }
  }, 30000);
});