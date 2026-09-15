// Login form elements.
const loginForm = document.getElementById("loginForm");
const message = document.getElementById("message");

// Display login progress, success and error messages.
function showMessage(text, type) {
  message.textContent = text;
  message.className = type;
}

// Login functions.
loginForm.addEventListener("submit", async function (event) {
  event.preventDefault();
  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;
  const rememberMe = document.getElementById("rememberMe").checked;
  const submitButton = loginForm.querySelector('button[type="submit"]');

  try {
    submitButton.disabled = true;
    submitButton.textContent = "Signing in...";
    const response = await fetch("api.php?action=login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ email, password }),
    });
    const result = await response.json();
    if (!result.ok) throw new Error(result.error || "Login failed");

    if (rememberMe) localStorage.setItem("rememberedEmail", email);
    else localStorage.removeItem("rememberedEmail");

    const user = result.user;
    const roleLabel = user.role_name || user.role;
    showMessage(
      `Login successful!\nDetected role: ${roleLabel}\nRedirecting to your dashboard...`,
      "success",
    );

    // The redirect destination comes from the database roles table, not a JS role map.
    setTimeout(() => {
      window.location.href = user.dashboard_path || "index.html";
    }, 500);
  } catch (error) {
    showMessage(error.message || "Unable to sign in.", "error");
    submitButton.disabled = false;
    submitButton.textContent = "Sign In";
  }
});

// Restore the remembered email address when the page opens.
window.addEventListener("load", () => {
  const rememberedEmail = localStorage.getItem("rememberedEmail");
  if (rememberedEmail) {
    document.getElementById("email").value = rememberedEmail;
    document.getElementById("rememberMe").checked = true;
  }
});
