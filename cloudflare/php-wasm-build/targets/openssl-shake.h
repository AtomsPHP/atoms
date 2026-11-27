#include <openssl/evp.h>
#include <openssl/x509.h>

/* Preserve the digest lengths used by the Playground PHP Worker. */
static inline unsigned int atoms_shake_length(const EVP_MD *method)
{
	switch (EVP_MD_get_type(method)) {
		case NID_shake128: return 16;
		case NID_shake256: return 32;
		default: return 0;
	}
}

static inline int atoms_x509_digest(X509 *cert, const EVP_MD *method, unsigned char *digest, unsigned int *length)
{
	*length = atoms_shake_length(method);
	if (!*length) {
		return X509_digest(cert, method, digest, length);
	}

	/* X509_digest hashes the certificate DER. Use the public XOF interface
	 * for the two methods which require an explicit output length. */
	unsigned char *der = NULL;
	int der_len = i2d_X509(cert, &der);
	EVP_MD_CTX *ctx = EVP_MD_CTX_new();
	int ok = der_len > 0 && ctx && EVP_DigestInit_ex(ctx, method, NULL)
		&& EVP_DigestUpdate(ctx, der, (size_t)der_len)
		&& EVP_DigestFinalXOF(ctx, digest, *length);
	EVP_MD_CTX_free(ctx);
	OPENSSL_free(der);
	return ok;
}
