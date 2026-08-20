@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">

                <h3 class="text-center mb-3">Reset Password</h3>

                <form id="resetForm">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="email" name="email" class="form-control mb-3" placeholder="Your email" autocomplete="email" required>
                    <div class="input-group mb-3">
                        <input type="password" name="password" id="password" class="form-control" placeholder="New password" autocomplete="new-password" required minlength="8">
                        <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide new password">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="input-group mb-3">
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="Confirm password" autocomplete="new-password" required>
                        <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password_confirmation', this)" aria-label="Show or hide password confirmation">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="resetBtn">Reset Password</button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('login') }}">Back to Login</a>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="slb-toast-stack" id="toastContainer"></div>

<script>
function togglePassword(id, el){
    const input = document.getElementById(id);
    const icon = el.querySelector('i');
    if(!input || !icon) return;

    if(input.type === 'password'){
        input.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

document.getElementById('resetForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const resetBtn = document.getElementById('resetBtn');
    if(resetBtn.disabled) return;
    resetBtn.disabled = true;
    resetBtn.innerText = 'Resetting...';

    const formData = new FormData(this);
    let data;

    try {
        const res = await fetch("{{ route('password.update') }}", {
            method:'POST',
            credentials: 'same-origin',
            headers:{
                'X-CSRF-TOKEN':'{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: formData
        });
        data = await res.json();
    } catch(e){
        slbAlert({ icon: 'error', title: 'Server error', text: 'Please try again in a moment.' });
        resetBtn.disabled = false;
        resetBtn.innerText = 'Reset Password';
        return;
    }

    const toastContainer = document.getElementById('toastContainer');
    const toastEl = document.createElement('div');
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.className = 'toast align-items-center text-white border-0';
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${data.message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
    toastEl.classList.add(data.status==='success'?'bg-success':'bg-danger');
    toastContainer.appendChild(toastEl);
    new bootstrap.Toast(toastEl,{delay:5000}).show();

    if(data.status==='success'){
        this.reset();
        setTimeout(()=>{ window.location.href = "{{ route('login') }}"; }, 1500);
    }

    resetBtn.disabled = false;
    resetBtn.innerText = 'Reset Password';
});
</script>
@endsection