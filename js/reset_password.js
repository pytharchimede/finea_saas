document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("reset-password-request-form");
  const messageDiv = document.querySelector(".message");
  const spinner = document.querySelector(".spinner");

  if (!form || !messageDiv || !spinner) return;

  const BASE_MESSAGE_CLASSES = [
    "mt-4",
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
    } else {
      messageDiv.classList.add("border-red-200", "bg-red-50", "text-red-800");
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    spinner.style.display = "inline-block";
    messageDiv.style.display = "none";

    const formData = new FormData(form);

    fetch("request/password_reset_request.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        spinner.style.display = "none";
        setMessage(
          data.status === "success" ? "success" : "error",
          data.message,
        );
      })
      .catch(() => {
        spinner.style.display = "none";
        setMessage("error", "Une erreur est survenue, veuillez réessayer.");
      });
  });
});
