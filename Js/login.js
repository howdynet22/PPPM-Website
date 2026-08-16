const loginForm = document.getElementById('loginForm');
const message = document.getElementById('message');

function showMessage(text, color) {
  message.style.color = color;
  message.textContent = text;
}

loginForm.addEventListener('submit', async function (e) {
  e.preventDefault();
  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const rememberMe = document.getElementById('rememberMe').checked;
  const submitButton = loginForm.querySelector('button[type="submit"]');

  try {
    submitButton.disabled = true;
    submitButton.textContent = 'Signing in...';
    const response = await fetch('api.php?action=login', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({email, password})
    });
    const result = await response.json();
    if (!result.ok) throw new Error(result.error || 'Login failed');

    if (rememberMe) localStorage.setItem('rememberedEmail', email);
    else localStorage.removeItem('rememberedEmail');

    const user = result.user;
    const roleLabel = user.role_name || user.role;
    showMessage(`Login successful!\nDetected role: ${roleLabel}\nRedirecting to your dashboard...`, 'green');

    // The redirect destination comes from the database roles table, not a JS role map.
    setTimeout(() => { window.location.href = user.dashboard_path || 'index.html'; }, 500);
  } catch (err) {
    showMessage(err.message || 'Unable to sign in.', 'red');
    submitButton.disabled = false;
    submitButton.textContent = 'Sign In';
  }
});

window.addEventListener('load', () => {
  const rememberedEmail = localStorage.getItem('rememberedEmail');
  if (rememberedEmail) {
    document.getElementById('email').value = rememberedEmail;
    document.getElementById('rememberMe').checked = true;
  }
});
