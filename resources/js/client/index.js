import axios from "axios";
import store from "../store/index";

const getClient = (baseUrl = null) => {
    const options = {
        baseURL: baseUrl,
    };
    if (store.getters["auth/loggedIn"]) {
        options.headers = {
            Authorization: `Bearer ${localStorage.getItem("token")}`,
        };
    }

    const client = axios.create(options);
    // Add a request interceptor
    client.interceptors.request.use(
        (requestConfig) => requestConfig,
        (requestError) => {
            return Promise.reject(requestError);
        }
    );
    // Add a response interceptor
    client.interceptors.response.use(
        (response) => response,
        (error) => {
            if (error.response.status === 401) {
                localStorage.removeItem("token");
                window.location.replace("/login");
            }
            return Promise.reject(error);
        }
    );
    return client;
};

export default {
    get(url, conf = {}) {
        return getClient()
            .get(url, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    delete(url, conf = {}) {
        return getClient()
            .delete(url, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    head(url, conf = {}) {
        return getClient()
            .head(url, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    options(url, conf = {}) {
        return getClient()
            .options(url, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    post(url, data = {}, conf = {}) {
        return getClient()
            .post(url, data, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    put(url, data = {}, conf = {}) {
        return getClient()
            .put(url, data, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
    patch(url, data = {}, conf = {}) {
        return getClient()
            .patch(url, data, conf)
            .then((response) => Promise.resolve(response))
            .catch((error) => Promise.reject(error));
    },
};
