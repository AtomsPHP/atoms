// PHP 8.3 uses the historical OpenSSL SHAKE output sizes for both digest
// and certificate fingerprint. OpenSSL 3.5's XOF API takes the length.
#[allow(non_upper_case_globals, non_camel_case_types, non_snake_case)]
mod ffi {
    include!(env!("ATOMS_CRYPTO_BINDINGS"));
}
use core::ptr::{null, null_mut};
use ffi::*;
use ffi::{NID_shake128 as NID_SHAKE128, NID_shake256 as NID_SHAKE256};

static mut PROVIDER: *mut OSSL_PROVIDER = null_mut();

#[no_mangle]
pub unsafe extern "C" fn atoms_load_legacy_provider() -> i32 {
    PROVIDER = OSSL_PROVIDER_try_load(null_mut(), c"legacy".as_ptr(), 1);
    if PROVIDER.is_null() {
        -1
    } else {
        0
    }
}

pub unsafe extern "C" fn unload_provider(_: i32, _: i32) -> i32 {
    if !PROVIDER.is_null() {
        OSSL_PROVIDER_unload(PROVIDER);
        PROVIDER = null_mut();
    }
    0
}

unsafe fn shake_size(md: *const EVP_MD) -> Option<u32> {
    match EVP_MD_get_type(md) as u32 {
        NID_SHAKE128 => Some(16),
        NID_SHAKE256 => Some(32),
        _ => None,
    }
}

#[no_mangle]
pub unsafe extern "C" fn atoms_php_digest_size(md: *const EVP_MD) -> i32 {
    match shake_size(md) {
        Some(len) => len as i32,
        None => EVP_MD_get_size(md),
    }
}

#[no_mangle]
pub unsafe extern "C" fn atoms_php_digest_final(
    ctx: *mut EVP_MD_CTX, out: *mut u8, len: *mut u32,
) -> i32 {
    if let Some(bytes) = shake_size(EVP_MD_CTX_get0_md(ctx)) {
        *len = bytes;
        EVP_DigestFinalXOF(ctx, out, bytes as usize)
    } else {
        EVP_DigestFinal(ctx, out, len)
    }
}

#[no_mangle]
pub unsafe extern "C" fn atoms_php_x509_digest(
    cert: *const X509, md: *const EVP_MD, out: *mut u8, len: *mut u32,
) -> i32 {
    let Some(bytes) = shake_size(md) else {
        return X509_digest(cert, md, out, len);
    };
    let mut der = null_mut();
    let der_len = i2d_X509(cert, &mut der);
    let ctx = EVP_MD_CTX_new();
    let ok = der_len > 0 && !ctx.is_null()
        && EVP_DigestInit_ex(ctx, md, null_mut()) == 1
        && EVP_DigestUpdate(ctx, der.cast(), der_len as usize) == 1
        && EVP_DigestFinalXOF(ctx, out, bytes as usize) == 1;
    EVP_MD_CTX_free(ctx);
    CRYPTO_free(der.cast(), null(), 0);
    if ok {
        *len = bytes;
    }
    i32::from(ok)
}
