// Password-help instructions.
document
  .getElementById("forgotForm")
  .addEventListener("submit", function (event) {
    event.preventDefault();

    const result = document.getElementById("result");
    result.textContent =
      "Public password reset is not enabled. " +
      "Contact your system administrator to verify your identity " +
      "and restore access.";
  });
