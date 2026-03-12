// Angular modules
import { Injectable }               from '@angular/core';
import { Router }                   from '@angular/router';

// External modules
import { ArrayTyper }               from '@caliatys/array-typer';
import { TranslateService }         from '@ngx-translate/core';
import axios                        from 'axios';
import { AxiosResponse }            from 'axios';
import { AxiosError }               from 'axios';
import { AxiosInstance }            from 'axios';
import { CreateAxiosDefaults }      from 'axios';

// Internal modules
import { environment }              from '@env/environment';

// Helpers
import { StorageHelper }            from '@helpers/storage.helper';

// Enums
import { Endpoint }                 from '@enums/endpoint.enum';

// Models

// Services
import { StoreService }             from './store.service';

@Injectable()
export class AppService
{
  // NOTE Default configuration
  private default : CreateAxiosDefaults = {
    withCredentials : true,
    timeout : 990000,
    headers : {
      'Content-Type' : 'application/json',
      'Accept'       : 'application/json',
    },
  };

  // NOTE Instances
  private api : AxiosInstance = axios.create({
    baseURL : environment.apiBaseUrl,
    ...this.default,
  });

  // NOTE Controller
  private controller : AbortController = new AbortController();

  constructor
  (
    private storeService     : StoreService,
    private router           : Router,
    private translateService : TranslateService,
  )
  {
    this.initRequestInterceptor(this.api);
    this.initResponseInterceptor(this.api);

    this.initAuthHeader();
  }

  // ----------------------------------------------------------------------------------------------
  // SECTION Methods ------------------------------------------------------------------------------
  // ----------------------------------------------------------------------------------------------

  public async authenticate(email : string, password : string) : Promise<boolean>
  {
    return Promise.resolve(true);
  }

  public async forgotPassword(email : string) : Promise<boolean>
  {
    return Promise.resolve(true);
  }

  public async validateAccount(token : string, password : string) : Promise<boolean>
  {
    return Promise.resolve(true);
  }

  // !SECTION Methods

  // ----------------------------------------------------------------------------------------------
  // SECTION Helpers ------------------------------------------------------------------------------
  // ----------------------------------------------------------------------------------------------

  private initAuthHeader() : void
  {
  }

  public initRequestInterceptor(instance : AxiosInstance) : void
  {
    instance.interceptors.request.use((config) =>
    {
      this.storeService.isLoading.set(true);

      return config;
    },
    (error) =>
    {
      this.storeService.isLoading.set(false);

      console.error('Request error:', error);
      return Promise.reject(error);
    });
  }

  public initResponseInterceptor(instance : AxiosInstance) : void
  {
    instance.interceptors.response.use((response) =>
    {
      this.storeService.isLoading.set(false);

      return response;
    },
    async (error : AxiosError) =>
    {
      this.storeService.isLoading.set(false);

      // NOTE Prevent request canceled error
      if (error.code === 'ERR_CANCELED')
        return Promise.resolve(error);

      console.error('Response error:', error.message);
      return Promise.reject(error);
    });
  }

  // !SECTION Helpers
}