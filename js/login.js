// js/login.js
document.addEventListener("DOMContentLoaded", function () {
  const form =
    document.getElementById("login-form") || document.querySelector("form");
  const messageDiv = document.querySelector(".message");
  const spinner = document.querySelector(".spinner");
  const attemptsDiv = document.querySelector(".attempts-info");

  if (!form || !messageDiv || !spinner) return;

  const BASE_MESSAGE_CLASSES = [
    "rounded-2xl",
    "border",
    "px-4",
    "py-3",
    "text-sm",
    "animate-fade-in",
  ];
  const STATE_MESSAGE_CLASSES = [
    "border-green-200",
    "bg-green-50",
    "text-green-800",
    "border-red-200",
    "bg-red-50",
    "text-red-800",
    "border-amber-200",
    "bg-amber-50",
    "text-amber-800",
    "success",
    "error",
  ];

  function setMessage(type, text) {
    messageDiv.style.display = "block";
    messageDiv.textContent = text;

    STATE_MESSAGE_CLASSES.forEach((cls) => messageDiv.classList.remove(cls));
    BASE_MESSAGE_CLASSES.forEach((cls) => messageDiv.classList.add(cls));

    if (type === "success") {
      messageDiv.classList.add(
        "border-green-200",
        "bg-green-50",
        "text-green-800",
      );
    } else if (type === "warning") {
      messageDiv.classList.add(
        "border-amber-200",
        "bg-amber-50",
        "text-amber-800",
      );
    } else {
      messageDiv.classList.add("border-red-200", "bg-red-50", "text-red-800");
    }
  }

  function renderAttempts(data) {
    if (!attemptsDiv) return;
    if (typeof data?.max_attempts !== "number") return;
    if (typeof data?.attempts_remaining !== "number") return;

    const max = data.max_attempts;
    const remaining = data.attempts_remaining;
    attemptsDiv.style.display = "block";
    attemptsDiv.textContent = `Essais restants : ${remaining}/${max}`;
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    // Affiche le spinner
    spinner.style.display = "inline-block";
    messageDiv.style.display = "none";

    const formData = new FormData(form);

    fetch("request/login_handler.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        spinner.style.display = "none";
        renderAttempts(data);

        if (data.status === "success") {
          setMessage("success", data.message);
          const targetUrl =
            typeof data.redirect === "string" && data.redirect.trim() !== ""
              ? data.redirect
              : "selection_portail.php";
          setTimeout(() => (window.location.href = targetUrl), 800);
        } else {
          if (data.code === "passkey_required" && typeof window.activatePasskeyOnlyLogin === "function") {
            window.activatePasskeyOnlyLogin();
            return;
          }
          const type = data.code === "account_locked" ? "warning" : "error";
          setMessage(type, data.message);
        }
      })
      .catch((error) => {
        spinner.style.display = "none";
        setMessage("error", "Une erreur est survenue, veuillez réessayer.");
      });
  });
});
